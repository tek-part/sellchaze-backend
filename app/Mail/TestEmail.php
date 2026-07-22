<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function build()
    {
        $address 	= 'sales@wig-pleasure.com';
        $subject 	= 'I just created new order on Best Beast!';
        $name 		= $this->data['name'];

        return $this->view('mail.test')
                    ->from($address, $name)
                    // ->cc($address, $name)
                    // ->bcc($address, $name)
                    ->replyTo($address, $name)
                    ->subject($subject)
                    ->with([
							'name'		=> $this->data['name'],
							'from'		=> $this->data['from'],
							'refnum'	=> $this->data['refnum'],
							'density' 	=> $this->data['density'],
							'length' 	=> $this->data['length'],
							'capsize' 	=> $this->data['capsize'],
							'category' 	=> $this->data['category'],
							'color' 	=> $this->data['color'],
							'texture' 	=> $this->data['texture'],
							'quantity' 	=> $this->data['quantity'],
							'captype'  	=> $this->data['captype']
						]);
    }
}