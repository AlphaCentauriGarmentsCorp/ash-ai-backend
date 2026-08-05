{{-- Table layout and inline styles only: Outlook and Gmail strip <style> blocks and
     ignore most of flexbox, so anything structural has to live on the elements. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0; padding:0; background-color:#F6F1E7;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#FFFFFF; border:1px solid #E4DACA;">
                <tr>
                    <td style="padding:28px 32px 8px 32px; font-family:Helvetica,Arial,sans-serif;">
                        <p style="margin:0; font-size:13px; letter-spacing:3px; text-transform:uppercase; color:#F97B0C; font-weight:bold;">REEFER MNL</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0 32px; font-family:Helvetica,Arial,sans-serif;">
                        <h1 style="margin:0 0 12px 0; font-size:24px; line-height:30px; color:#101010; font-weight:bold;">Confirm your email</h1>
                        <p style="margin:0 0 4px 0; font-size:15px; line-height:23px; color:#101010;">Hi {{ $name }},</p>
                        <p style="margin:0 0 20px 0; font-size:15px; line-height:23px; color:#101010;">
                            Enter this code in the app to finish verifying your address.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 32px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#F6F1E7; border:1px solid #E4DACA;">
                            <tr>
                                {{-- user-select is a no-op in most clients; the code is plain
                                     text in a cell precisely so long-press/copy still works. --}}
                                <td align="center" style="padding:22px 12px; font-family:'Courier New',Courier,monospace; font-size:38px; line-height:44px; letter-spacing:10px; color:#101010; font-weight:bold;">{{ $code }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px 32px 32px; font-family:Helvetica,Arial,sans-serif;">
                        <p style="margin:0 0 16px 0; font-size:14px; line-height:21px; color:#5A5347;">
                            The code expires in {{ $ttlMinutes }} minutes. If it runs out, ask for a new one from the app.
                        </p>
                        <p style="margin:0; font-size:14px; line-height:21px; color:#5A5347;">
                            Didn't ask for this? You can ignore this email — nothing changes until the code is used.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 28px 32px; font-family:Helvetica,Arial,sans-serif; border-top:1px solid #E4DACA;">
                        <p style="margin:18px 0 0 0; font-size:12px; line-height:18px; color:#8A8272;">
                            REEFER MNL &middot; Manila, Philippines
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
