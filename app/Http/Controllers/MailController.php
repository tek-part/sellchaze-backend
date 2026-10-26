<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mail;
use App\Http\Requests;
use App\Http\Controllers\Controller;
class MailController extends Controller
{
    public function txt_mail()
    {
        $info = array(
            'name' => "Alex"
        );
        Mail::send(['text' => 'mail'], $info, function ($message)
        {
            $message->to('yasser4ever1@gmail.com', 'W3SCHOOLS')
                ->subject('Basic test eMail from W3schools.');
            $message->from('sender@example.com', 'Alex');
        });
        echo "Successfully sent the email";
    }

    public function html_mail()
    {
        $info = array(
            'name' => "Alex"
        );
        Mail::send('mail', $info, function ($message)
        {
            $message->to('yasser4ever1@gmail.com', 'w3schools')
                ->subject('HTML test eMail from W3schools.');
            $message->from('karlosray@gmail.com', 'Alex');
        });
        echo "Successfully sent the email";
    }

    public function attached_mail()
    {
        $info = array(
            'name' => "Alex"
        );
        Mail::send('mail', $info, function ($message)
        {
            $message->to('yasser4ever1@gmail.com', 'w3schools')
                ->subject('Test eMail with an attachment from W3schools.');
            $message->attach('https://upload.wikimedia.org/wikipedia/commons/thumb/b/b6/Image_created_with_a_mobile_phone.png/330px-Image_created_with_a_mobile_phone.png');
            //$message->attach('D:\laravel_main\laravel\public\uploads\message_mail.txt');
            $message->from('karlosray@gmail.com', 'Alex');
        });
        echo "Successfully sent the email";
    }
}