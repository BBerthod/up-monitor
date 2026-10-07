<?php

namespace App\Enums;

enum ChannelType: string
{
    case EMAIL = 'email';
    case WEBHOOK = 'webhook';
    case SLACK = 'slack';
    case DISCORD = 'discord';
    case PUSH = 'push';
    case TELEGRAM = 'telegram';
}
