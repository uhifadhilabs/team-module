<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Team Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Team\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Uhifadhi\Team\Entity\User;

/**
 * THE TWO LETTERS THIS MODULE SENDS, and the one question every screen that
 * offers to send one has to ask first: is there anything to send them with.
 *
 * THE MAILER IS OPTIONAL AND THE NULL IS THE ANSWER. `symfony/mailer` is a
 * suggestion rather than a requirement, so an installation that never sends
 * mail does not carry it — and where the package is absent, or present but with
 * no transport configured, the service simply is not in the container and this
 * class is constructed with null. One check, {@see isConfigured()}, covers both,
 * which is right because from a screen's point of view they are the same fact.
 *
 * WHAT THE SCREENS DO WITH THAT ANSWER IS NOT SILENCE. Invite-by-email is
 * OFFERED AND REFUSED where there is no mailer — the form visible, the button
 * inert, the reason written on it — because hiding it would leave an
 * administrator hunting for a feature the product does have, and swallowing the
 * click would leave a colleague waiting for an email nobody sent. A silently
 * discarded reset is the worst failure either of these flows has.
 *
 * THE LETTERS ARE PLAIN TEXT. A module bundle that shipped an HTML email would
 * be shipping a second design system nobody theming this installation can
 * reach, and the whole content of both letters is one sentence and one link.
 */
final readonly class Mail
{
    public function __construct(
        private ?MailerInterface $mailer = null,
        private string $from = '',
        private string $installationName = 'Uhifadhi',
    ) {
    }

    /**
     * Whether this installation can send at all — the fact the invite screen and
     * the forgot-password screen both turn on.
     *
     * A FROM ADDRESS IS PART OF IT. A transport with nothing to send from is a
     * transport that will fail at the moment of sending rather than at the
     * moment of asking, which is the failure this check exists to move earlier.
     */
    public function isConfigured(): bool
    {
        return null !== $this->mailer && '' !== $this->from;
    }

    /** Step 2 of the invite flow: the link that lets somebody set their own password. */
    public function sendInvitation(User $user, string $acceptUrl): void
    {
        $this->send(
            (string) $user->getEmail(),
            \sprintf('You have been added to %s', $this->installationName),
            <<<TEXT
                You have been added to {$this->installationName}.

                Choose a password and the account is yours:

                {$acceptUrl}

                Nobody here knows this password and nobody here can see it — that is
                the point of sending you a link rather than handing you one.

                If you were not expecting this, ignore the message. Nothing happens
                until somebody sets a password, and the link stops working once one
                is set.
                TEXT,
        );
    }

    /**
     * The reset link. ONE HOUR, ONE USE, and the letter says both — stated
     * before you ask rather than discovered afterwards.
     */
    public function sendPasswordReset(User $user, string $resetUrl): void
    {
        $this->send(
            (string) $user->getEmail(),
            \sprintf('Set a new %s password', $this->installationName),
            <<<TEXT
                Somebody asked to reset the password for this address at
                {$this->installationName}.

                Set a new one here:

                {$resetUrl}

                The link is good for ONE HOUR and works ONCE. Using it signs every
                other session of this account out, on every device — that is the
                point of a reset.

                If you did not ask for this, ignore the message. Nothing has changed
                yet, and the link expires by itself.
                TEXT,
        );
    }

    /**
     * NOTHING IS ATTEMPTED WITHOUT A TRANSPORT. The callers all ask
     * {@see isConfigured()} first and say so where the answer is no; this guard
     * is the second one, so that a caller added later cannot make the failure
     * silent by forgetting.
     */
    private function send(string $to, string $subject, string $body): void
    {
        if (null === $this->mailer || '' === $this->from) {
            return;
        }

        $this->mailer->send(
            new Email()
                ->from($this->from)
                ->to($to)
                ->subject($subject)
                ->text($body),
        );
    }
}
