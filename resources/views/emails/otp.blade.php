<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify Code — {{ config('app.name', 'Otantik') }}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet" />
  <style>
    /* Reset & outlook client-specific styles */
    body, table, td, a {
      -webkit-text-size-adjust: 100%;
      -ms-text-size-adjust: 100%;
    }
    table, td {
      mso-table-lspace: 0pt;
      mso-table-rspace: 0pt;
    }
    img {
      -ms-interpolation-mode: bicubic;
    }
    body {
      margin: 0;
      padding: 0;
      width: 100% !important;
      height: 100% !important;
      background-color: #f9f5f0;
      font-family: "Jost", ui-sans-serif, system-ui, -apple-system, sans-serif;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }
    img {
      border: 0;
      height: auto;
      line-height: 100%;
      outline: none;
      text-decoration: none;
    }
    table {
      border-collapse: collapse !important;
    }
    
    /* Responsive styles */
    @media only screen and (max-width: 600px) {
      .container-table {
        width: 100% !important;
        max-width: 100% !important;
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
      }
      .header-padding {
        padding: 40px 20px !important;
      }
      .body-padding {
        padding: 40px 20px !important;
      }
      .otp-digit {
        width: 38px !important;
        height: 44px !important;
        font-size: 20px !important;
      }
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f9f5f0;">
  
  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f9f5f0; padding: 40px 0 60px 0;">
    <tr>
      <td align="center" valign="top">
        
        <!-- Main Email Container (Card Wrapper) -->
        <table class="container-table" border="0" cellpadding="0" cellspacing="0" width="550" style="width: 550px; background-color: #ffffff; border: 1px solid #E8E2D9; border-radius: 16px; overflow: hidden; box-shadow: 0 24px 80px rgba(38, 35, 32, 0.08);">
          
          <!-- Elegant Dark Header Section -->
          <tr>
            <td class="header-padding" align="center" valign="top" style="background-color: #262320; padding: 50px 40px; text-align: center; border-bottom: 3px solid #d4af37;">
              <!-- Small Logo/Branding Header -->
              <div style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 16px; font-weight: 400; color: #d4af37; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 15px;">
                {{ config('app.name', 'Otantik') }}
              </div>
              <h1 style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 36px; font-weight: 300; color: #ffffff; margin: 0; line-height: 1.2; letter-spacing: 0.5px;">
                Verify <span style="color: #d4af37; font-style: italic;">Code</span>
              </h1>
              <table align="center" border="0" cellpadding="0" cellspacing="0" width="80" style="margin-top: 20px; margin-bottom: 20px;">
                <tr>
                  <td height="2" style="height: 2px; background: linear-gradient(to right, #d4af37, #262320); background-color: #d4af37; line-height: 2px; font-size: 2px;">&nbsp;</td>
                </tr>
              </table>
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 14px; color: #9CA3AF; margin: 0; line-height: 1.6; font-weight: 300; max-width: 400px;">
                Use the verification code below to complete your authentication request.
              </p>
            </td>
          </tr>
          
          <!-- Light Content Section -->
          <tr>
            <td class="body-padding" align="left" valign="top" style="background-color: #ffffff; padding: 45px 40px;">
              
              <!-- Info Notice Block -->
              <div style="border-radius: 8px; border: 1px solid #E8E2D9; background-color: #f9f5f0; padding: 18px 20px; margin-bottom: 35px;">
                <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-weight: 500; font-size: 14px; color: #262320; margin: 0 0 4px 0; line-height: 1.4;">
                  Verification Code for {{ $purposeLabel }}
                </p>
                <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 13px; color: #6B7280; margin: 0; line-height: 1.5;">
                  Check your inbox and enter the 6-digit code below.
                </p>
              </div>
              
              <!-- OTP Display Block -->
              @php
                $digits = str_split($code);
              @endphp
              <table align="center" border="0" cellpadding="0" cellspacing="0" style="margin: 35px auto;">
                <tr>
                  @foreach($digits as $digit)
                    <td class="otp-digit" align="center" valign="middle" width="45" height="50" style="width: 45px; height: 50px; background-color: #ffffff; border: 1px solid #d4af37; border-radius: 6px; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 24px; font-weight: 600; color: #262320; box-shadow: 0 2px 4px rgba(38,35,32,0.04);">
                      {{ $digit }}
                    </td>
                    @if(!$loop->last)
                      <td width="8" style="width: 8px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                    @endif
                  @endforeach
                </tr>
              </table>
              
              <!-- Expiration details -->
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 14px; color: #262320; text-align: center; margin: 25px 0 10px 0; line-height: 1.5;">
                This code expires in <strong style="color: #d4af37; font-weight: 600;">{{ $expiresInMinutes }} minutes</strong>.
              </p>
              
              <!-- Override notice for staging/testing environment if applicable -->
              @if ($sentToOverride)
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-top: 25px;">
                  <tr>
                    <td style="border-radius: 8px; border: 1px dashed #fecaca; background-color: #fef2f2; color: #b91c1c; padding: 15px; font-size: 12px; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; text-align: center; line-height: 1.5;">
                      <strong>Testing Mode Note:</strong> This OTP was requested for <span style="text-decoration: underline;">{{ $intendedFor }}</span> and delivered to <span style="text-decoration: underline;">{{ $deliveredTo }}</span>.
                    </td>
                  </tr>
                </table>
              @endif
              
              <!-- Safety advice -->
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 13px; color: #9CA3AF; text-align: center; margin: 35px 0 0 0; line-height: 1.6;">
                If you did not request this code, you can safely ignore this email. Your account remains secure.
              </p>
              
            </td>
          </tr>
          
          <!-- Elegant Footer Section -->
          <tr>
            <td align="center" valign="top" style="background-color: #f9f5f0; padding: 30px 40px; border-top: 1px solid #E8E2D9; text-align: center;">
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #9CA3AF; margin: 0; line-height: 1.5;">
                &copy; {{ date('Y') }} {{ config('app.name', 'Otantik') }}. All rights reserved.
              </p>
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 11px; color: #9CA3AF; margin: 5px 0 0 0; line-height: 1.5;">
                This is an automated security notification. Please do not reply directly to this email.
              </p>
            </td>
          </tr>
          
        </table>
        
      </td>
    </tr>
  </table>

</body>
</html>
