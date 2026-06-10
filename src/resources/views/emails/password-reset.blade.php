{{--
    Forgot-password email body (Phase D7).

    Variables passed by PasswordResetMail::content():
      - $recipientName : the user's display name
      - $resetUrl      : full URL to /reset-password with the raw
                         token + email as query params (only place
                         the raw token ever appears)
      - $expiresAt     : DateTimeInterface of the link's expiry
--}}
<x-mail::message>
# Reset your password, {{ $recipientName }}

We received a request to reset the password for your MITHQAL Merchant Portal account.

Click the button below to choose a new password. This link expires on {{ $expiresAt->format('F j, Y \a\t H:i') }} UTC and can be used once.

<x-mail::button :url="$resetUrl">
Reset my password
</x-mail::button>

If the button does not work, copy and paste this URL into your browser:

[{{ $resetUrl }}]({{ $resetUrl }})

If you did not request a password reset, you can safely ignore this email — your password will not change until the link is used.

Thanks,
The MITHQAL Team
</x-mail::message>
