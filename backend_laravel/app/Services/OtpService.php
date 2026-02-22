<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function generate(User $user): OtpCode
    {
        // Invalidate old codes
        OtpCode::where('user_id', $user->id)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        // Create new 6-digit code
        $otp = OtpCode::create([
            'user_id'    => $user->id,
            'code'       => str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'expires_at' => now()->addMinutes(5),
        ]);

        // Send email
        Mail::to($user->email)->send(new OtpMail($otp->code));

        return $otp;
    }

    public function verify(User $user, string $code): bool
    {
        $otp = OtpCode::where('user_id', $user->id)
            ->where('code', $code)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            return false;
        }

        $otp->update(['is_used' => true]);
        return true;
    }
}
