{{-- Inline styles and table layout only: Gmail and Outlook strip <style> blocks,
     and the brand's palette is the one thing that has to survive that. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your REEFER MNL password</title>
</head>
<body style="margin:0; padding:0; background-color:#F6F1E7; color:#101010; -webkit-font-smoothing:antialiased;">

    {{-- Preheader: the grey line the inbox shows next to the subject. Hidden in the
         body itself, otherwise the client picks the first visible words instead. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#F6F1E7; font-size:1px; line-height:1px;">
        A link to set a new password. Good for {{ $expiresInMinutes }} minutes.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F6F1E7;">
        <tr>
            <td align="center" style="padding:40px 16px;">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#F6F1E7;">

                    <tr>
                        <td style="padding:0 0 32px 0; font-family:Helvetica,Arial,sans-serif; font-size:20px; font-weight:700; letter-spacing:0.22em; color:#101010; text-transform:uppercase;">
                            Reefer<span style="color:#F97B0C;">&nbsp;MNL</span>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 20px 0; font-family:Helvetica,Arial,sans-serif; font-size:28px; line-height:1.25; font-weight:700; color:#101010;">
                            Let&rsquo;s get you back in.
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 16px 0; font-family:Helvetica,Arial,sans-serif; font-size:16px; line-height:1.65; color:#101010;">
                            Hi {{ $user->name }},
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 32px 0; font-family:Helvetica,Arial,sans-serif; font-size:16px; line-height:1.65; color:#101010;">
                            Someone asked to reset the password on the REEFER MNL account tied to
                            <strong style="font-weight:700;">{{ $user->email }}</strong>. Tap below to set a new one.
                        </td>
                    </tr>

                    {{-- Button as a padded anchor rather than a table cell with a link inside:
                         fewer clients get the tap target wrong, and it degrades to plain text. --}}
                    <tr>
                        <td style="padding:0 0 32px 0;">
                            <a href="{{ $resetUrl }}"
                               style="display:inline-block; background-color:#F97B0C; color:#101010; font-family:Helvetica,Arial,sans-serif; font-size:15px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; text-decoration:none; padding:16px 36px; border-radius:2px;">
                                Set a new password
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 8px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; line-height:1.6; color:#101010; opacity:0.7;">
                            Button not working? Paste this into your browser:
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 32px 0; font-family:Menlo,Consolas,monospace; font-size:12px; line-height:1.6; color:#101010; word-break:break-all;">
                            <a href="{{ $resetUrl }}" style="color:#101010; text-decoration:underline;">{{ $resetUrl }}</a>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 32px 0; border-top:1px solid rgba(16,16,16,0.15);"></td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 16px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.65; color:#101010;">
                            This link expires in <strong style="font-weight:700;">{{ $expiresInMinutes }} minutes</strong> and works once.
                            After that, ask for a fresh one from the sign-in page.
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 32px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.65; color:#101010;">
                            Didn&rsquo;t ask for this? Ignore it &mdash; nothing changes until that link is used, and your
                            current password still works.
                        </td>
                    </tr>

                    <tr>
                        <td style="font-family:Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#101010; opacity:0.6;">
                            REEFER MNL &middot; Manila, Philippines<br>
                            Sent because a password reset was requested for this address.
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
