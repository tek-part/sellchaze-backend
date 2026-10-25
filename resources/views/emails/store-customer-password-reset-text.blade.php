{{ $ar ? 'مرحبًا' : 'Hi' }} {{ $name }},

{{ $ar ? 'طلبت إعادة تعيين كلمة مرور حسابك في '.$store->name.'. افتح الرابط التالي لاختيار كلمة مرور جديدة:' : 'We received a request to reset the password for your '.$store->name.' account. Open this link to choose a new password:' }}

{{ $url }}

{{ $ar ? 'ينتهي الرابط خلال '.$minutes.' دقيقة. إن لم تطلب ذلك فتجاهل هذه الرسالة.' : 'The link expires in '.$minutes.' minutes. If you did not ask for this, you can ignore this email.' }}
