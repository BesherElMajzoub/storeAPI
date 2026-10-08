@php
  $brand = config('mail.brand.name', 'Otantik Queen');
  $logo = config('mail.brand.logo_url');
  $support = config('mail.brand.support_email');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title') — {{ $brand }}</title>
  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    table { border-collapse: collapse !important; }
    img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
    body { margin: 0; padding: 0; width: 100% !important; background-color: #f9f5f0; font-family: "Jost", "Helvetica Neue", Arial, sans-serif; }
    @media only screen and (max-width: 600px) {
      .container-table { width: 100% !important; border-radius: 0 !important; border-left: none !important; border-right: none !important; }
      .pad { padding: 32px 20px !important; }
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f9f5f0;">
  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f9f5f0; padding: 40px 0 60px 0;">
    <tr>
      <td align="center" valign="top">
        <table class="container-table" border="0" cellpadding="0" cellspacing="0" width="550" style="width: 550px; background-color: #ffffff; border: 1px solid #E8E2D9; border-radius: 16px; overflow: hidden;">
          <tr>
            <td class="pad" align="center" style="background-color: #262320; padding: 44px 40px; text-align: center; border-bottom: 3px solid #d4af37;">
              @if ($logo)
                <img src="{{ $logo }}" alt="{{ $brand }}" height="48" style="display: block; margin: 0 auto 16px auto; height: 48px;" />
              @else
                <div style="font-family: Georgia, serif; font-size: 16px; color: #d4af37; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 14px;">{{ $brand }}</div>
              @endif
              <h1 style="font-family: Georgia, serif; font-size: 30px; font-weight: 300; color: #ffffff; margin: 0; line-height: 1.25;">@yield('heading')</h1>
            </td>
          </tr>
          <tr>
            <td class="pad" align="left" style="padding: 40px; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 14px; color: #262320; line-height: 1.6;">
              @yield('content')
            </td>
          </tr>
          <tr>
            <td align="center" style="background-color: #f9f5f0; padding: 26px 40px; border-top: 1px solid #E8E2D9; text-align: center; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #9CA3AF; line-height: 1.5;">
              &copy; {{ date('Y') }} {{ $brand }}. All rights reserved.
              @if ($support)
                <br />Questions? Write to <a href="mailto:{{ $support }}" style="color: #9CA3AF;">{{ $support }}</a>.
              @endif
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
