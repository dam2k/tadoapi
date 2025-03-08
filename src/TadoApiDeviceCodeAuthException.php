<?php
declare(strict_types=1);

namespace dAm2K;

class TadoApiDeviceCodeAuthException extends \Exception
{
    protected $message = 'Unknown error authenticating device';
}
