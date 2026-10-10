@extends('emails.layouts.brand')

@section('title', 'Your verification code')
@section('preheader', 'Your Otantik Queen code is '.$code.'. It expires in '.$expiresInMinutes.' minutes.')
@section('heading')
  Your verification <span style="color: #d4af37; font-style: italic;">code</span>
@endsection
@section('subheading', 'Enter this code to '.$purposeLabel.'.')
@section('footer_note', 'This is an automated security email. Please do not reply to it.')

@section('content')
  <p style="margin: 0 0 10px 0; text-align: center; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; color: #5b6170;">Your code</p>

  {{-- One box, one string: the code can be selected and copied in one go. The smaller right padding offsets the letter-spacing after the last digit. --}}
  <table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto 22px auto;">
    <tr>
      <td class="code" align="center" bgcolor="#f9f5f0" style="padding: 16px 18px 16px 28px; border: 1px solid #d4af37; border-radius: 8px; background-color: #f9f5f0; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 34px; font-weight: 600; letter-spacing: 10px; line-height: 40px; color: #262320; mso-line-height-rule: exactly;">{{ $code }}</td>
    </tr>
  </table>

  <p style="margin: 0; text-align: center;">
    It expires in <strong style="color: #7a5f12;">{{ $expiresInMinutes }} minutes</strong>.
  </p>

  @if ($sentToOverride)
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-top: 26px;">
      <tr>
        <td style="border-radius: 8px; border: 1px dashed #fecaca; background-color: #fef2f2; color: #b91c1c; padding: 14px 16px; font-size: 12px; text-align: center; line-height: 1.5;">
          <strong>Testing mode:</strong> this code was requested for {{ $intendedFor }} and delivered to {{ $deliveredTo }}.
        </td>
      </tr>
    </table>
  @endif

  <p style="margin: 30px 0 0 0; text-align: center; font-size: 13px; color: #5b6170;">
    Didn't ask for this code? You can ignore this email; your account stays secure.
  </p>
@endsection
