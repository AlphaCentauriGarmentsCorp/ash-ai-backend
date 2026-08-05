{{-- Tables and inline styles throughout, deliberately: mail clients strip <style>
     blocks and have no opinion about flexbox. Brand palette matches the storefront —
     cream #F6F1E7, ink #101010, orange #F97B0C. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $productName }} is back</title>
</head>
<body style="margin:0; padding:0; background:#F6F1E7; color:#101010; font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F6F1E7; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:560px; background:#FFFDF8; border:2px solid #101010;">

                <tr>
                    <td style="background:#101010; color:#F6F1E7; padding:18px 20px; font-size:20px; font-weight:800; letter-spacing:2px;">
                        REEFER MNL
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 20px 8px;">
                        <p style="margin:0 0 10px; font-size:28px; font-weight:800; line-height:1.1; text-transform:uppercase;">
                            Your size is back.
                        </p>
                        <p style="margin:0; font-size:15px; line-height:1.55; color:#6B6357;">
                            {{ $name }}, you asked us to tell you. The {{ $productName }} is back in
                            <span style="color:#101010; font-weight:700;">size {{ $size }}</span>.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 20px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:2px solid #101010; background:#F97B0C;">
                            <tr>
                                <td style="padding:14px; font-size:16px; font-weight:800; text-transform:uppercase; line-height:1.2;">
                                    {{ $productName }}<br>
                                    <span style="font-size:12px; font-weight:700; letter-spacing:1.5px;">Size {{ $size }}</span>
                                </td>
                                <td align="right" style="padding:14px; font-size:18px; font-weight:800; white-space:nowrap;">
                                    {{ $priceFormatted }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- The storefront's hard offset shadow, drawn as cells: Outlook ignores
                     box-shadow, so the orange sits beside and under the ink button. --}}
                <tr>
                    <td style="padding:24px 20px 4px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background:#101010; border:2px solid #101010;">
                                    <a href="{{ $productUrl }}" style="display:inline-block; padding:14px 26px; color:#F6F1E7; font-size:14px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; text-decoration:none;">
                                        Grab it now
                                    </a>
                                </td>
                                <td width="5" style="width:5px; background:#F97B0C; font-size:1px; line-height:1px;">&nbsp;</td>
                            </tr>
                            <tr>
                                <td height="5" style="height:5px; background:#F97B0C; font-size:1px; line-height:1px;">&nbsp;</td>
                                <td height="5" width="5" style="height:5px; width:5px; background:#F97B0C; font-size:1px; line-height:1px;">&nbsp;</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 20px 26px;">
                        <p style="margin:0 0 12px; font-size:13px; line-height:1.6; color:#6B6357;">
                            Restocks move fast and nothing is held for you — first checkout wins.
                        </p>
                        <p style="margin:0; font-size:13px; line-height:1.6; color:#6B6357;">
                            This is a one-off. We will not email you about this size again unless you ask us to.
                            Link not working? Paste this in your browser:
                            <br><span style="color:#101010; word-break:break-all;">{{ $productUrl }}</span>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background:#101010; color:#F6F1E7; padding:14px 20px; font-size:11px; letter-spacing:1.5px; text-transform:uppercase;">
                        Reefer MNL &middot; Salt, asphalt, and good cotton.
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
