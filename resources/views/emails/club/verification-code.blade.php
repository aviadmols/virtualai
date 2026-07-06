{{--
    Customer-Club verification-code email. Static developer-authored template — the
    only dynamic values are the scalar {{ $code }} + {{ $minutes }} (blade-escaped),
    so no strtr/merchant text is involved. Inline CSS is the sanctioned exception for
    email HTML (clients strip <style>). All copy goes through __() (en/he 1:1).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('club.mail.dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('club.mail.subject') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;padding:32px;max-width:480px;">
                    <tr>
                        <td style="font-size:18px;font-weight:bold;padding-bottom:12px;">
                            {{ __('club.mail.heading') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;line-height:22px;color:#3f3f46;padding-bottom:20px;">
                            {{ __('club.mail.intro') }}
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-bottom:20px;">
                            <div style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#18181b;background-color:#f4f4f5;border-radius:8px;padding:16px 0;">
                                {{ $code }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:13px;line-height:20px;color:#71717a;">
                            {{ __('club.mail.expiry', ['minutes' => $minutes]) }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:13px;line-height:20px;color:#71717a;padding-top:8px;">
                            {{ __('club.mail.ignore') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
