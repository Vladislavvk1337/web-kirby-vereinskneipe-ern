<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\User\Authentication;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Konto anlegen oder Passwort/Gruppe setzen:
 *
 *   echo "$PASSWORT" | bin/plugin kneipe user --username=anna --email=anna@example.org \
 *       --group=administration --name="Anna Beispiel" --password-stdin
 *
 * Gruppen: administration | moderation (user/config/groups.yaml).
 * Das Passwort kommt über STDIN, damit es nicht in der Prozessliste steht.
 */
class UserCommand extends ConsoleCommand
{
    private const GROUPS = ['administration', 'moderation'];

    protected function configure(): void
    {
        $this->setName('user')
            ->setDescription('Konto der Redaktion anlegen oder aktualisieren (Gruppe administration oder moderation)')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Benutzername (a-z, 0-9, . _ -)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-Mail-Adresse')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'administration oder moderation', 'moderation')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Anzeigename')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Passwort von STDIN lesen')
            ->addOption('if-none', null, InputOption::VALUE_NONE, 'Nur anlegen, wenn noch kein Konto existiert (Erststart)');
    }

    protected function serve(): int
    {
        $grav     = Grav::instance();
        $input    = $this->input;
        $username = strtolower(trim((string)$input->getOption('username')));
        $email    = strtolower(trim((string)$input->getOption('email')));
        $group    = (string)$input->getOption('group');

        /** @var UserCollectionInterface $accounts */
        $accounts = $grav['accounts'];
        $dir      = (string)$grav['locator']->findResource('account://', true, true);

        if ($input->getOption('if-none') && glob($dir . '/*.yaml') !== []) {
            $this->output->writeln('Konten vorhanden – nichts zu tun.');

            return 0;
        }

        if (preg_match('/^[a-z0-9._-]{3,64}$/', $username) !== 1) {
            $this->output->writeln('<error>Ungültiger Benutzername (3–64 Zeichen: a-z, 0-9, . _ -).</error>');

            return 2;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->output->writeln('<error>Ungültige E-Mail-Adresse.</error>');

            return 2;
        }

        if (!in_array($group, self::GROUPS, true)) {
            $this->output->writeln('<error>Gruppe muss administration oder moderation sein.</error>');

            return 2;
        }

        $password = '';

        if ($input->getOption('password-stdin')) {
            $password = rtrim((string)stream_get_contents(STDIN), "\r\n");
        }

        $user   = $accounts->load($username);
        $exists = $user->exists();

        if (!$exists && strlen($password) < 12) {
            $this->output->writeln('<error>Für ein neues Konto ist ein Passwort mit mindestens 12 Zeichen nötig (--password-stdin).</error>');

            return 2;
        }

        $user->set('email', $email);
        $user->set('state', 'enabled');
        $user->set('language', 'de');
        $user->set('groups', [$group]);
        $user->set('title', $group === 'administration' ? 'Administration' : 'Moderation');
        $user->set('access', ['site' => ['login' => true]]);

        if ($input->getOption('name')) {
            $user->set('fullname', trim((string)$input->getOption('name')));
        } elseif (!$exists) {
            $user->set('fullname', $username);
        }

        if ($password !== '') {
            if (strlen($password) < 12) {
                $this->output->writeln('<error>Das Passwort muss mindestens 12 Zeichen haben.</error>');

                return 2;
            }

            $user->set('hashed_password', Authentication::create($password));
            $user->undef('password');
        }

        $user->save();
        @chmod($dir . '/' . $username . '.yaml', 0660);

        $this->output->writeln(sprintf('Konto %s %s (Gruppe %s).', $username, $exists ? 'aktualisiert' : 'angelegt', $group));

        return 0;
    }
}
