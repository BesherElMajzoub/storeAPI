@php
  $brand = config('mail.brand.name', 'Otantik Queen');
  $logo = config('mail.brand.logo_url');
  $support = config('mail.brand.support_email') ?: 'otantikqueen@gmail.com';
  $siteUrl = rtrim(config('app.frontend_url', 'https://otantikqueen.com'), '/');
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="x-apple-disable-message-reformatting" />
  <meta name="color-scheme" content="light" />
  <meta name="supported-color-schemes" content="light" />
  <title>@yield('title') — {{ $brand }}</title>
  {{-- Web fonts load in Apple Mail and iOS; Gmail and Outlook fall back to Georgia / Helvetica. --}}
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;1,400&family=Jost:wght@400;500;600&display=swap" rel="stylesheet" />
  <style>
    :root { color-scheme: light; supported-color-schemes: light; }
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    table { border-collapse: collapse !important; }
    img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
    body { margin: 0; padding: 0; width: 100% !important; background-color: #f9f5f0; font-family: "Jost", "Helvetica Neue", Arial, sans-serif; }
    @media only screen and (max-width: 600px) {
      .container-table { width: 100% !important; border-radius: 0 !important; border-left: none !important; border-right: none !important; }
      .pad { padding: 32px 22px !important; }
      .heading { font-size: 28px !important; }
      .code { font-size: 28px !important; letter-spacing: 7px !important; padding: 14px 13px 14px 20px !important; }
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f9f5f0;">
  @hasSection('preheader')
    {{-- Inbox preview line; hidden in the email itself. The padding characters stop clients from pulling body text into the preview. --}}
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all; font-size: 1px; line-height: 1px; color: #f9f5f0; opacity: 0;">
      @yield('preheader')
      &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp; &#847; &zwnj; &nbsp;
    </div>
  @endif
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f9f5f0;">
    <tr>
      <td align="center" valign="top" style="padding: 40px 0 56px 0;">
        <table role="presentation" class="container-table" border="0" cellpadding="0" cellspacing="0" width="560" style="width: 560px; background-color: #ffffff; border: 1px solid #E8E2D9; border-radius: 16px; overflow: hidden;">

          {{-- Header: wordmark (or logo), heading, gold rule, optional one-line summary --}}
          <tr>
            <td class="pad" align="center" bgcolor="#262320" style="background-color: #262320; padding: 44px 40px 40px 40px; text-align: center; border-bottom: 3px solid #d4af37;">
              @if ($logo)
                <img src="{{ $logo }}" alt="{{ $brand }}" height="48" style="display: block; margin: 0 auto 18px auto; height: 48px;" />
              @else
                <div style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 15px; color: #d4af37; text-transform: uppercase; letter-spacing: 4px; margin: 0 0 16px 0;">{{ $brand }}</div>
              @endif
              <h1 class="heading" style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 34px; font-weight: 400; color: #ffffff; margin: 0; line-height: 1.2; letter-spacing: 0.3px;">@yield('heading')</h1>
              <table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="60" style="margin: 18px auto 0 auto;">
                <tr>
                  <td height="2" style="height: 2px; background-color: #d4af37; line-height: 2px; font-size: 2px;">&nbsp;</td>
                </tr>
              </table>
              @hasSection('subheading')
                {{-- Centred block: auto side margins centre the 420 px box itself, align/text-align centre its lines. --}}
                <p align="center" style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 15px; color: #c9c2b6; margin: 18px auto 0 auto; max-width: 420px; line-height: 1.6; text-align: center;">@yield('subheading')</p>
              @endif
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td class="pad" align="left" style="padding: 40px; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 15px; color: #262320; line-height: 1.65;">
              @yield('content')
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td align="center" bgcolor="#f9f5f0" style="background-color: #f9f5f0; padding: 26px 40px; border-top: 1px solid #E8E2D9; text-align: center; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #5b6170; line-height: 1.7;">
              Questions? Write to <a href="mailto:{{ $support }}" style="color: #7a5f12; text-decoration: underline;">{{ $support }}</a><br />
              <a href="{{ $siteUrl }}" style="color: #5b6170; text-decoration: none;">otantikqueen.com</a> &nbsp;·&nbsp; &copy; {{ date('Y') }} {{ $brand }}
              @hasSection('footer_note')
                <br /><span style="font-size: 11px;">@yield('footer_note')</span>
              @endif
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
