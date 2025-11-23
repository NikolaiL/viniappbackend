<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Viniapp extends Model
{
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'wallet_private_key',
    ];

    /**
     * Get the decrypted private key
     * 
     * @return string|null
     */
    public function getDecryptedPrivateKey(): ?string
    {
        if (empty($this->wallet_private_key)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->wallet_private_key);
        } catch (\Exception $e) {
            // If decryption fails, the key might be stored in plain text (legacy data)
            // or there's an issue with the encryption key
            return null;
        }
    }
}
