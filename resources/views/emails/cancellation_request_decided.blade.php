<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Cancellation Request {{ ucfirst($decision) }} — {{ config('app.name', 'Otantik') }}</title>
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
              <h1 style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 32px; font-weight: 300; color: #ffffff; margin: 0; line-height: 1.2; letter-spacing: 0.5px;">
                Request <span style="color: #d4af37; font-style: italic;">{{ $decision === 'accepted' ? 'Approved' : 'Declined' }}</span>
              </h1>
              <table align="center" border="0" cellpadding="0" cellspacing="0" width="80" style="margin-top: 15px; margin-bottom: 15px;">
                <tr>
                  <td height="2" style="height: 2px; background-color: #d4af37; line-height: 2px; font-size: 2px;">&nbsp;</td>
                </tr>
              </table>
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 14px; color: #9CA3AF; margin: 0; line-height: 1.6; font-weight: 300; max-width: 400px;">
                Your cancellation request status has been updated.
              </p>
            </td>
          </tr>
          
          <!-- Light Content Section -->
          <tr>
            <td class="body-padding" align="left" valign="top" style="background-color: #ffffff; padding: 45px 40px;">
              
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 16px; color: #262320; margin: 0 0 15px 0; font-weight: 500;">
                Hello,
              </p>
              
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 14px; color: #6B7280; margin: 0 0 25px 0; line-height: 1.6;">
                We have processed your cancellation request for order <strong style="color: #262320;">#{{ $orderNumber }}</strong>.
              </p>
              
              <!-- Decision Status Badge Box -->
              <div style="margin-bottom: 30px; text-align: center;">
                @if($decision === 'accepted')
                  <span style="display: inline-block; padding: 6px 18px; border-radius: 20px; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-weight: 600; font-size: 12px; letter-spacing: 1px; background-color: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; text-transform: uppercase;">
                    Request Approved ✓
                  </span>
                @else
                  <span style="display: inline-block; padding: 6px 18px; border-radius: 20px; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-weight: 600; font-size: 12px; letter-spacing: 1px; background-color: #fef2f2; border: 1px solid #fecaca; color: #ef4444; text-transform: uppercase;">
                    Request Declined ✗
                  </span>
                @endif
              </div>
              
              <!-- Informative text depending on decision -->
              <div style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 14px; color: #262320; line-height: 1.6; margin-bottom: 30px;">
                @if($decision === 'accepted')
                  <p style="margin: 0;">
                    Your order has been successfully cancelled and a refund has been initiated. Please allow <strong>3 to 5 business days</strong> for the refunded amount to appear in your bank account or payment method.
                  </p>
                @else
                  <p style="margin: 0;">
                    Unfortunately, your cancellation request has been declined. Your order will continue to be processed and shipped as normal. You will receive tracking details once it has dispatched.
                  </p>
                @endif
              </div>
              
              <!-- Admin Note from the Team -->
              @if($adminNote)
                <div style="border-radius: 8px; border-left: 4px solid #d4af37; background-color: #f9f5f0; padding: 18px 20px; margin-bottom: 30px; border-top: 1px solid #E8E2D9; border-right: 1px solid #E8E2D9; border-bottom: 1px solid #E8E2D9;">
                  <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-weight: 600; font-size: 13px; color: #262320; margin: 0 0 6px 0; text-transform: uppercase; letter-spacing: 0.5px;">
                    Note from our team:
                  </p>
                  <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 13px; color: #6B7280; margin: 0; line-height: 1.5; font-style: italic;">
                    "{{ $adminNote }}"
                  </p>
                </div>
              @endif
              
              <!-- View Order Action Button -->
              <table align="center" border="0" cellpadding="0" cellspacing="0" style="margin: 35px auto 10px auto;">
                <tr>
                  <td align="center" style="border-radius: 6px; background-color: #262320;">
                    <a href="{{ config('app.frontend_url', 'http://localhost:8000') }}/orders/{{ $orderNumber }}" target="_blank" style="display: inline-block; padding: 12px 28px; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 13px; font-weight: 500; color: #ffffff; text-decoration: none; border-radius: 6px; border: 1px solid #d4af37; background-color: #262320; text-transform: uppercase; letter-spacing: 1px;">
                      View Order Details
                    </a>
                  </td>
                </tr>
              </table>
              
              <p style="font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 13px; color: #9CA3AF; text-align: center; margin: 30px 0 0 0; line-height: 1.6;">
                If you have any questions or need further assistance, please feel free to reach out to our dedicated support team.
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
                This is an automated notification regarding order #{{ $orderNumber }}.
              </p>
            </td>
          </tr>
          
        </table>
        
      </td>
    </tr>
  </table>

</body>
</html>
