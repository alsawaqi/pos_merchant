{{--
    Set-password / reset-password link for a merchant teammate.

    Variables passed by SetPasswordLinkMail::content():
      - $recipientName : the user's display name
      - $companyName   : the merchant's name, or null
      - $purpose       : invite | reset
      - $url           : the set-password link (the only place the raw
                         token appears besides the one-time copy dialog)
      - $expiresAt     : CarbonInterface, the link's expiry (UTC)
--}}
<x-mail::message>
@if ($purpose === 'invite')
# Welcome to MITHQAL, {{ $recipientName }}

@if ($companyName)
A login was created for you on the **{{ $companyName }}** team in the MITHQAL Merchant Portal.
@else
A login was created for you in the MITHQAL Merchant Portal.
@endif

Click the button below to choose your password. The link works once and expires on {{ $expiresAt->format('F j, Y \a\t H:i') }} UTC.

<x-mail::button :url="$url">
Set my password
</x-mail::button>
@else
# Reset your password, {{ $recipientName }}

Your MITHQAL Merchant Portal password was reset by your team's administrator. Your old password no longer works.

Click the button below to choose a new password. The link works once and expires on {{ $expiresAt->format('F j, Y \a\t H:i') }} UTC.

<x-mail::button :url="$url">
Choose a new password
</x-mail::button>
@endif

If the button does not work, copy and paste this address into your browser:

[{{ $url }}]({{ $url }})

If you were not expecting this email, you can ignore it.

Thanks,
The MITHQAL Team
</x-mail::message>
