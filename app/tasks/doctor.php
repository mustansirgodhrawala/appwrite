<?php

global $cli;

use Appwrite\ClamAV\Network;
use Utopia\Logger\Logger;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Domains\Domain;
use Utopia\Validator\Text;
use Utopia\Validator\Wildcard;

$cli
    ->task('doctor')
    ->desc('Validate server health')
    ->param('interactive', 'n', new Text(1), 'Run an interactive session', true)
    ->param('receiver', '', new Wildcard(), 'Send an email to this address to test SMTP', true)
    ->action(function ($interactive, $receiver) use ($register) {
        Console::log("  __   ____  ____  _  _  ____  __  ____  ____     __  __  
 / _\ (  _ \(  _ \/ )( \(  _ \(  )(_  _)(  __)   (  )/  \ 
/    \ ) __/ ) __/\ /\ / )   / )(   )(   ) _)  _  )((  O )
\_/\_/(__)  (__)  (_/\_)(__\_)(__) (__) (____)(_)(__)\__/ ");

        Console::log("\n" . '👩‍⚕️ Running ' . APP_NAME . ' Doctor for version ' . App::getEnv('_APP_VERSION', 'UNKNOWN') . ' ...' . "\n");

        Console::log('Checking for production best practices...');

        $domain = new Domain(App::getEnv('_APP_DOMAIN'));

        if (!$domain->isKnown() || $domain->isTest()) {
            Console::log('🔴 Hostname has no public suffix (' . $domain->get() . ')');
        } else {
            Console::log('🟢 Hostname has a public suffix (' . $domain->get() . ')');
        }

        $domain = new Domain(App::getEnv('_APP_DOMAIN_TARGET'));

        if (!$domain->isKnown() || $domain->isTest()) {
            Console::log('🔴 CNAME target has no public suffix (' . $domain->get() . ')');
        } else {
            Console::log('🟢 CNAME target has a public suffix (' . $domain->get() . ')');
        }

        if (App::getEnv('_APP_OPENSSL_KEY_V1') === 'your-secret-key' || empty(App::getEnv('_APP_OPENSSL_KEY_V1'))) {
            Console::log('🔴 Not using a unique secret key for encryption');
        } else {
            Console::log('🟢 Using a unique secret key for encryption');
        }

        if (App::getEnv('_APP_ENV', 'development') !== 'production') {
            Console::log('🔴 App environment is set for development');
        } else {
            Console::log('🟢 App environment is set for production');
        }

        if ('enabled' !== App::getEnv('_APP_OPTIONS_ABUSE', 'disabled')) {
            Console::log('🔴 Abuse protection is disabled');
        } else {
            Console::log('🟢 Abuse protection is enabled');
        }

        $authWhitelistRoot = App::getEnv('_APP_CONSOLE_WHITELIST_ROOT', null);
        $authWhitelistEmails = App::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null);
        $authWhitelistIPs = App::getEnv('_APP_CONSOLE_WHITELIST_IPS', null);

        if (
            empty($authWhitelistRoot)
            && empty($authWhitelistEmails)
            && empty($authWhitelistIPs)
        ) {
            Console::log('🔴 Console access limits are disabled');
        } else {
            Console::log('🟢 Console access limits are enabled');
        }

        if ('enabled' !== App::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled')) {
            Console::log('🔴 HTTPS force option is disabled');
        } else {
            Console::log('🟢 HTTPS force option is enabled');
        }


        $providerName = App::getEnv('_APP_LOGGING_PROVIDER', '');
        $providerConfig = App::getEnv('_APP_LOGGING_CONFIG', '');

        if (empty($providerName) || empty($providerConfig) || !Logger::hasProvider($providerName)) {
            Console::log('🔴 Logging adapter is disabled');
        } else {
            Console::log('🟢 Logging adapter is enabled (' . $providerName . ')');
        }

        \sleep(0.2);

        try {
            Console::log("\n" . 'Checking connectivity...');
        } catch (\Throwable $th) {
            //throw $th;
        }

        try {
            $register->get('db'); /* @var $db PDO */
            Console::success('Database............connected 👍');
        } catch (\Throwable $th) {
            Console::error('Database.........disconnected 👎');
        }

        try {
            $register->get('cache');
            Console::success('Queue...............connected 👍');
        } catch (\Throwable $th) {
            Console::error('Queue............disconnected 👎');
        }

        try {
            $register->get('cache');
            Console::success('Cache...............connected 👍');
        } catch (\Throwable $th) {
            Console::error('Cache............disconnected 👎');
        }

        if (App::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled') { // Check if scans are enabled
            try {
                $antivirus = new Network(
                    App::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                    (int) App::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
                );

                if ((@$antivirus->ping())) {
                    Console::success('Antivirus...........connected 👍');
                } else {
                    Console::error('Antivirus........disconnected 👎');
                }
            } catch (\Throwable $th) {
                Console::error('Antivirus........disconnected 👎');
            }
        }


        try {
            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */
            if ($receiver != '') {
                $mail->addAddress($receiver);
            } elseif ($interactive == "Y" || $interactive == "y") {
                $receiver = Console::confirm('Enter your email address to test SMTP connection: ');
                $mail->addAddress($receiver);
            } else {
                $mail->addAddress('demo@example.com', 'Example.com');
            }

            $mail->Subject = 'Test SMTP Connection';
            $mail->Body = 'Hello World';
            $mail->AltBody = 'Hello World';

            $mail->send();
            Console::success('SMTP................connected 👍');
        } catch (\Throwable $th) {
            Console::error('SMTP.............disconnected 👎');
        }

        $host = App::getEnv('_APP_STATSD_HOST', 'telegraf');
        $port = App::getEnv('_APP_STATSD_PORT', 8125);

        if ($fp = @\fsockopen('udp://' . $host, $port, $errCode, $errStr, 2)) {
            Console::success('StatsD..............connected 👍');
            \fclose($fp);
        } else {
            Console::error('StatsD...........disconnected 👎');
        }

        $host = App::getEnv('_APP_INFLUXDB_HOST', '');
        $port = App::getEnv('_APP_INFLUXDB_PORT', '');

        if ($fp = @\fsockopen($host, $port, $errCode, $errStr, 2)) {
            Console::success('InfluxDB............connected 👍');
            \fclose($fp);
        } else {
            Console::error('InfluxDB.........disconnected 👎');
        }

        \sleep(0.2);

        Console::log('');
        Console::log('Checking volumes...');

        foreach (
            [
            'Uploads' => APP_STORAGE_UPLOADS,
            'Cache' => APP_STORAGE_CACHE,
            'Config' => APP_STORAGE_CONFIG,
            'Certs' => APP_STORAGE_CERTIFICATES
            ] as $key => $volume
        ) {
            $device = new Local($volume);

            if (\is_readable($device->getRoot())) {
                Console::success('🟢 ' . $key . ' Volume is readable');
            } else {
                Console::error('🔴 ' . $key . ' Volume is unreadable');
            }

            if (\is_writable($device->getRoot())) {
                Console::success('🟢 ' . $key . ' Volume is writeable');
            } else {
                Console::error('🔴 ' . $key . ' Volume is unwriteable');
            }
        }

        \sleep(0.2);

        Console::log('');
        Console::log('Checking disk space usage...');

        foreach (
            [
            'Uploads' => APP_STORAGE_UPLOADS,
            'Cache' => APP_STORAGE_CACHE,
            'Config' => APP_STORAGE_CONFIG,
            'Certs' => APP_STORAGE_CERTIFICATES
            ] as $key => $volume
        ) {
            $device = new Local($volume);

            $percentage = (($device->getPartitionTotalSpace() - $device->getPartitionFreeSpace())
            / $device->getPartitionTotalSpace()) * 100;

            $message = $key . ' Volume has ' . Storage::human($device->getPartitionFreeSpace()) . ' free space (' . \round($percentage, 2) . '% used)';

            if ($percentage < 80) {
                Console::success('🟢 ' . $message);
            } else {
                Console::error('🔴 ' . $message);
            }
        }

        try {
            if (App::isProduction()) {
                Console::log('');
                $version = \json_decode(@\file_get_contents(App::getEnv('_APP_HOME', 'http://localhost') . '/v1/health/version'), true);

                if ($version && isset($version['version'])) {
                    if (\version_compare($version['version'], App::getEnv('_APP_VERSION', 'UNKNOWN')) === 0) {
                        Console::info('You are running the latest version of ' . APP_NAME . '! 🥳');
                    } else {
                        Console::info('A new version (' . $version['version'] . ') is available! 🥳' . "\n");
                    }
                } else {
                    Console::error('Failed to check for a newer version' . "\n");
                }
            }
        } catch (\Throwable $th) {
            Console::error('Failed to check for a newer version' . "\n");
        }
    });
