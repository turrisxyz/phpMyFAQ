<?php

/**
 * The import function to import the phpMyFAQ backups.
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License,
 * v. 2.0. If a copy of the MPL was not distributed with this file, You can
 * obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package   phpMyFAQ
 * @author    Thorsten Rinne <thorsten@phpmyfaq.de>
 * @copyright 2003-2022 phpMyFAQ Team
 * @license   https://www.mozilla.org/MPL/2.0/ Mozilla Public License Version 2.0
 * @link      https://www.phpmyfaq.de
 * @since     2003-02-24
 */

use phpMyFAQ\Component\Alert;
use phpMyFAQ\Database;
use phpMyFAQ\Database\DatabaseHelper;
use phpMyFAQ\Filter;
use phpMyFAQ\Strings;

if (!defined('IS_VALID_PHPMYFAQ')) {
    http_response_code(400);
    exit();
}

$csrfToken = Filter::filterInput(INPUT_GET, 'csrf', FILTER_UNSAFE_RAW);

if (!isset($_SESSION['phpmyfaq_csrf_token']) || $_SESSION['phpmyfaq_csrf_token'] !== $csrfToken) {
    $csrfCheck = false;
} else {
    $csrfCheck = true;
}
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i aria-hidden="true" class="fa fa-download"></i>
            <?= $PMF_LANG['ad_csv_rest'] ?>
        </h1>
    </div>
<?php

if ($user->perm->hasPermission($user->getUserId(), 'restore') && $csrfCheck) {
    if (isset($_FILES['userfile']) && 0 === $_FILES['userfile']['error']) {
        $ok = 1;
        $fileInfo = new finfo(FILEINFO_MIME_ENCODING);

        if ('utf-8' !== $fileInfo->file($_FILES['userfile']['tmp_name'])) {
            echo 'This file is not UTF-8 encoded.<br>';
            $ok = 0;
        }
        $handle = fopen($_FILES['userfile']['tmp_name'], 'r');
        $backupData = fgets($handle, 65536);
        $versionFound = Strings::substr($backupData, 0, 9);
        $versionExpected = '-- pmf' . substr($faqConfig->getVersion(), 0, 3);
        $queries = [];

        if ($versionFound !== $versionExpected) {
            printf(
                '%s (Version check failure: "%s" found, "%s" expected)',
                $PMF_LANG['ad_csv_no'],
                $versionFound,
                $versionExpected
            );
            $ok = 0;
        } else {
            // @todo: Start transaction for better recovery if something really bad happens
            $backupData = trim(Strings::substr($backupData, 11));
            $tables = explode(' ', $backupData);
            $numTables = count($tables);
            for ($h = 0; $h < $numTables; ++$h) {
                $queries[] = sprintf('DELETE FROM %s', $tables[$h]);
            }
            $ok = 1;
        }

        if ($ok == 1) {
            $tablePrefix = '';
            printf("<p>%s</p>\n", $PMF_LANG['ad_csv_prepare']);
            while ($backupData = fgets($handle, 65536)) {
                $backupData = trim($backupData);
                $backupPrefixPattern = '-- pmftableprefix:';
                $backupPrefixPatternLength = Strings::strlen($backupPrefixPattern);
                if (Strings::substr($backupData, 0, $backupPrefixPatternLength) === $backupPrefixPattern) {
                    $tablePrefix = trim(Strings::substr($backupData, $backupPrefixPatternLength));
                }
                if ((Strings::substr($backupData, 0, 2) != '--') && ($backupData != '')) {
                    $queries[] = trim(Strings::substr($backupData, 0, -1));
                }
            }

            $k = 0;
            $g = 0;
            printf("<p>%s</p>\n", $PMF_LANG['ad_csv_process']);
            $numTables = count($queries);
            $kg = '';
            for ($i = 0; $i < $numTables; ++$i) {
                $queries[$i] = DatabaseHelper::alignTablePrefix($queries[$i], $tablePrefix, Database::getTablePrefix());
                $kg = $faqConfig->getDb()->query($queries[$i]);
                if (!$kg) {
                    printf(
                        '<div style="alert alert-danger"><strong>Query</strong>: "%s" failed (Reason: %s)</div>%s',
                        Strings::htmlspecialchars($queries[$i], ENT_QUOTES, 'utf-8'),
                        $faqConfig->getDb()->error(),
                        "\n"
                    );
                    ++$k;
                } else {
                    printf(
                        '<!-- <div class="alert alert-success"><strong>Query</strong>: "%s" okay</div> -->%s',
                        Strings::htmlspecialchars($queries[$i], ENT_QUOTES, 'utf-8'),
                        "\n"
                    );
                    ++$g;
                }
            }
            printf(
                '<p class="alert alert-success">%d %s %d %s</p>',
                $g,
                $PMF_LANG['ad_csv_of'],
                $numTables,
                $PMF_LANG['ad_csv_suc']
            );
        }
    } else {
        $errorMessage = match ($_FILES['userfile']['error']) {
            1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the ' . 'HTML form.',
            3 => 'The uploaded file was only partially uploaded.',
            4 => 'No file was uploaded.',
            6 => 'Missing a temporary folder.',
            7 => 'Failed to write file to disk.',
            8 => 'A PHP extension stopped the file upload.',
            default => 'Undefined error.',
        };
        echo Alert::danger('ad_csv_no', $errorMessage);
    }
} else {
    echo $PMF_LANG['err_NotAuth'];
}
