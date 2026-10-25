<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

/**
 * The default mailer must name a registered mailer and a recognised
 * transport. This check does not send mail.
 */
final class MailMailer extends ReferenceCheck
{
    public const ID = 'mail_mailer';

    protected function checkId(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return 'Default mailer';
    }

    protected function nameId(): string
    {
        return 'mail.default';
    }

    protected function definitionId(): string
    {
        return 'mail.mailer';
    }

    protected function driverId(): string
    {
        return 'mail.transport';
    }

    protected function subject(): string
    {
        return 'default mailer';
    }
}
