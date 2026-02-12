<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} OTP</title>
  </head>
  <body>
    <p>Hello,</p>
    <p>Your one-time code for {{ $purposeLabel }} is:</p>
    <p style="font-size: 24px; font-weight: bold;">{{ $code }}</p>
    <p>This code expires in {{ $expiresInMinutes }} minutes.</p>
    @if ($sentToOverride)
      <p>Note: This OTP was requested for {{ $intendedFor }} and delivered to {{ $deliveredTo }} for testing.</p>
    @endif
    <p>If you did not request this, you can ignore this email.</p>
    <p>Thanks,<br>{{ config('app.name') }}</p>
  </body>
</html>
