{{-- The one call-to-action button used by every email: @include('emails.partials.button', ['url' => ..., 'label' => ...]) --}}
<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
  <tr>
    <td align="center" bgcolor="#262320" style="border-radius: 6px; background-color: #262320;">
      <a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 14px 32px; font-family: 'Jost', 'Helvetica Neue', Arial, sans-serif; font-size: 13px; font-weight: 500; letter-spacing: 1.5px; text-transform: uppercase; color: #ffffff; text-decoration: none; border-radius: 6px; border: 1px solid #d4af37;">{{ $label }}</a>
    </td>
  </tr>
</table>
