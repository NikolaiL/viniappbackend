You're updating a mini app that started out as a scaffold-eth repo with miniapp extension

Understand sequentially
[ ] understand the design system, ensure that all future updates keep the spirit and extend from it
[ ] keep a composable and tested application
[ ] using typescript, scaffold-eth
[ ] use privy signed wallet for payment
[ ] below find the provided x402 and can reference all_x402scan_endpoints.json with AI to determine which tools are best used in the app and while developing the app
[ ] use .cursorrules and .cursor/rules/*.mdc files as reference.
[ ] you have access to ENV which has OPENROUTER_API_KEY

[ ] the users request is this, keeping the above in mind build a plan, review the plan and then work on this
[ ] Copy packages/nextjs/env.example to packages/nextjs/env.local and make required changes
[ ] Plan a color scheme and implement it in the packages/nextjs/styles/globals.css 
[ ] if miniapp requires a smart contract - implement it and change network in packages/hardhat/hardhat.config.ts
[ ] use wagmi for client wallet interactions and use privy for server-side wallet interactions required by x402 API access so that the API key is not publically revealed.

users prompt to make changes is:
**USERPROMPT**

x402 service code
import { Router } from 'express';
import { privateKeyToAccount } from 'viem/accounts';
import { wrapFetchWithPayment } from 'x402-fetch';
import connectDB from '../lib/mongodb.js';
import { getAIRequestModel, type EndpointCall, getBlacklistModel, getWhitelistModel, type EndpointFilter } from '../lib/mongodb/models.js';
import type { Model } from 'mongoose';
import { getSpendingLimits, checkPerEndpointLimit, checkTotalLimit, getCurrentSpending } from '../lib/limits.js';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const router = Router();

// Find endpoints file
const ENDPOINTS_FILE = [
  path.join(__dirname, '../../../all_x402scan_endpoints.json'),
  path.join(process.cwd(), 'all_x402scan_endpoints.json'),
].find((p) => fs.existsSync(p));

if (!ENDPOINTS_FILE) {
  console.warn('⚠️  all_x402scan_endpoints.json not found. Run: python3 fetch_all_endpoints.py');
}

interface Endpoint {
  resource: string;
  resourceId: string;
  description: string;
  network: string;
  maxAmountRequired: string;
  payTo: string;
  asset: string;
  input: {
    method?: string;
    bodyFields?: Record<string, any>;
    queryParams?: Record<string, any>;
  };
  output: Record<string, any>;
}

async function selectEndpoints(
  userPrompt: string,
  endpoints: Endpoint[],
  openRouterApiKey: string,
  whitelistResources?: Set<string>
): Promise<Array<{ endpoint: Endpoint; requiredParams: Record<string, any> }>> {
  // Extract keywords from user prompt for better matching
  const promptLower = userPrompt.toLowerCase();
  const keywords = promptLower.split(/\s+/).filter(w => w.length > 3);
  
  // Score and prioritize endpoints based on relevance
  const scoredEndpoints = endpoints.map((ep, idx) => {
    let score = 0;
    const descLower = ep.description.toLowerCase();
    const resourceLower = ep.resource.toLowerCase();
    
    // Check for exact keyword matches in description
    keywords.forEach(keyword => {
      if (descLower.includes(keyword)) score += 10;
      if (resourceLower.includes(keyword)) score += 5;
    });
    
    // Boost score for specific domain matches (e.g., "recipe" in prompt and endpoint)
    if (promptLower.includes('recipe') && (descLower.includes('recipe') || resourceLower.includes('recipe'))) {
      score += 50; // Strong boost for exact domain match
    }
    if (promptLower.includes('cook') && descLower.includes('recipe')) score += 30;
    if (promptLower.includes('ingredient') && descLower.includes('ingredient')) score += 30;
    
    // Boost whitelisted endpoints
    if (whitelistResources && whitelistResources.has(ep.resource)) {
      score += 20;
    }
    
    return { endpoint: ep, index: idx, score };
  });
  
  // Sort by score (highest first) and take top 500
  scoredEndpoints.sort((a, b) => b.score - a.score);
  const topEndpoints = scoredEndpoints.slice(0, 500);
  
  // Create a map to preserve original indices
  const indexMap = new Map<number, number>();
  topEndpoints.forEach((item, newIdx) => {
    indexMap.set(item.index, newIdx);
  });
  
  const endpointSummaries = topEndpoints.map((item, idx) => {
    const ep = item.endpoint;
    return {
      id: item.index, // Use original index for lookup
      resource: ep.resource,
      description: ep.description.substring(0, 200),
      method: ep.input?.method || 'GET',
      hasInputSchema: !!ep.input?.bodyFields || !!ep.input?.queryParams,
      hasOutputSchema: !!ep.output && Object.keys(ep.output).length > 0,
      cost: ep.maxAmountRequired,
      network: ep.network,
      isWhitelisted: whitelistResources ? whitelistResources.has(ep.resource) : false,
      relevanceScore: item.score, // Include score for AI consideration
      inputFields: ep.input?.bodyFields
        ? Object.keys(ep.input.bodyFields).slice(0, 10)
        : ep.input?.queryParams
          ? Object.keys(ep.input.queryParams).slice(0, 10)
          : [],
    };
  });

  const response = await fetch('https://openrouter.ai/api/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${openRouterApiKey}`,
      'HTTP-Referer': 'https://x402scan.com',
      'X-Title': 'x402scan AI Endpoint Caller',
    },
    body: JSON.stringify({
      model: 'x-ai/grok-4.1-fast',
      messages: [
        {
          role: 'system',
          content: `You are an AI assistant that selects the best API endpoints from x402scan based on user requests.

Available endpoints (sorted by relevance):
${JSON.stringify(endpointSummaries, null, 2)}

CRITICAL SELECTION RULES:
1. PRIORITIZE EXACT MATCHES: If the user mentions a specific domain (e.g., "recipe", "cooking", "ingredients"), STRONGLY prefer endpoints whose description or resource URL contains those exact keywords.
2. Check the "relevanceScore" field - higher scores indicate better matches.
3. If an endpoint's description directly matches the user's intent (e.g., "recipe" endpoint for recipe requests), it should be your TOP priority.
4. Look at "inputFields" to understand what parameters the endpoint expects and extract them from the user's prompt.

${whitelistResources && whitelistResources.size > 0 
  ? `5. Some endpoints are marked as "isWhitelisted": true. Give these endpoints preference when they are equally relevant.`
  : ''}

Your task:
1. Analyze the user's request carefully
2. Identify the PRIMARY intent (e.g., "recipe generation", "web search", "chat completion")
3. Select the MOST RELEVANT endpoint(s) that DIRECTLY match the user's intent (max 5)
4. For each selection, extract the required parameters from the user's prompt based on the endpoint's inputFields
5. If multiple endpoints could work, prefer the one with the highest relevanceScore

Response format:
{
  "selections": [
    {
      "endpointId": <number from the id field>,
      "reasoning": "<why this endpoint is the BEST match for the user's request>",
      "requiredParams": {<extracted parameters matching the endpoint's inputFields>}
    }
  ]
}

IMPORTANT: The endpointId must match the "id" field from the endpoint summaries above.`,
        },
        { role: 'user', content: userPrompt },
      ],
      temperature: 0.3,
      response_format: { type: 'json_object' },
    }),
  });

  const data = await response.json() as { choices?: Array<{ message?: { content?: string } }> };
  const content = data.choices?.[0]?.message?.content;
  const parsed = JSON.parse(content);
  const selections: Array<{ endpoint: Endpoint; requiredParams: Record<string, any> }> = [];

  for (const sel of parsed.selections || []) {
    const endpointIdx = parseInt(sel.endpointId);
    if (!isNaN(endpointIdx) && endpointIdx >= 0 && endpointIdx < endpoints.length) {
      selections.push({
        endpoint: endpoints[endpointIdx],
        requiredParams: sel.requiredParams || {},
      });
    }
  }

  return selections.slice(0, 5);
}

async function callEndpoint(
  endpoint: Endpoint,
  params: Record<string, any>,
  fetchWithPayment: ReturnType<typeof wrapFetchWithPayment>
): Promise<{ response?: any; error?: string; duration: number }> {
  const startTime = Date.now();
  const url = new URL(endpoint.resource);
  const method = endpoint.input?.method || 'GET';

  const init: RequestInit = {
    method,
    headers: {
      'Content-Type': 'application/json',
    },
  };

  if (['POST', 'PUT', 'PATCH'].includes(method) && endpoint.input?.bodyFields) {
    const body: Record<string, any> = {};
    Object.keys(endpoint.input.bodyFields).forEach((key) => {
      if (params[key] !== undefined) {
        body[key] = params[key];
      }
    });
    init.body = JSON.stringify(body);
  } else if (method === 'GET' && endpoint.input?.queryParams) {
    Object.entries(params).forEach(([key, value]) => {
      if (endpoint.input?.queryParams?.[key]) {
        url.searchParams.set(key, String(value));
      }
    });
  }

  try {
    const response = await fetchWithPayment(url.toString(), init);
    const duration = Date.now() - startTime;

    if (!response.ok) {
      const errorText = await response.text();
      return { error: `HTTP ${response.status}: ${errorText}`, duration };
    }

    const contentType = response.headers.get('content-type') || '';
    const result = contentType.includes('application/json')
      ? await response.json()
      : await response.text();

    return { response: result, duration };
  } catch (error: any) {
    return { error: error.message, duration: Date.now() - startTime };
  }
}
