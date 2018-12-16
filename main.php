<?php
require __DIR__ . '/vendor/autoload.php';

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

/**
 * Returns file size
 */
function formatSizeUnits($bytes)
{
    if ($bytes >= 1073741824)
    {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    }
    elseif ($bytes >= 1048576)
    {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    }
    elseif ($bytes >= 1024)
    {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    }
    elseif ($bytes > 1)
    {
        $bytes = $bytes . ' bytes';
    }
    elseif ($bytes == 1)
    {
        $bytes = $bytes . ' byte';
    }
    else
    {
        $bytes = '0 bytes';
    }

    return $bytes;
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('Google Drive API PHP');
    $client->setScopes(Google_Service_Drive::DRIVE);
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    // The file token.json stores the user's access and refresh tokens, and is
    // created automatically when the authorization flow completes for the first
    // time.
    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}

/**
 * uploadFile to Google_Service_Drive
 * @return status
 */
function uploadFile2Google($upload_file_name, $upload_file_path)
{
    // Get the API client and construct the service object.
    $client = getClient();
    $service = new Google_Service_Drive($client);
    ini_set('memory_limit','512M');

    $file = new Google_Service_Drive_DriveFile();
    $file->setName($upload_file_name);
    // Name:blog_backup ID:1RI9ksNJpk1pLeLTJvE_K6-KrDkjjgVGQ
    $file->setParents(array('1RI9ksNJpk1pLeLTJvE_K6-KrDkjjgVGQ'));
    $result = $service->files->create($file, array(
        'data' => file_get_contents($upload_file_path),
        'mimeType' => 'application/octet-stream',
        'uploadType' => 'multipart'
    ));

    printf("upload file success! \nfileName:%s fileID:%s\n", $result->getName(), $result->getId());

    // Print the names and IDs for up to 10 files.
    // search for files listed in folder blog_backup
    $optParams = array(
        'q' => "'1RI9ksNJpk1pLeLTJvE_K6-KrDkjjgVGQ' in parents",
        'pageSize' => 10,
        'fields' => 'nextPageToken, files(id, name,createdTime)'
    );
    $results = $service->files->listFiles($optParams);

    if (count($results->getFiles()) == 0) {
        print "No files found.\n";
    } else {
        print "List Files:\n";
        foreach ($results->getFiles() as $file) {
            $createdTime = date('m/d/Y h:i:s a', strtotime($file->getCreatedTime()));
            printf("%s (%s) createdTime:%s\n", $file->getName(), $file->getId(), $createdTime);
            $now = new DateTime(date("Y-m-d H:i:s"));
            $ct = new DateTime($createdTime);
            $interval=$now->diff($ct)->days;
            printf("time interval: %s\n", $interval);
            // keep 7 backups to save storage space
            if ($interval > 6) {
                printf("delete file %s (%s) createdTime:%s\n", $file->getName(), $file->getId(), $createdTime);
                $service->files->delete($file->getId());
            }
        }
    }
}

/**
 * backup website and database
 * @return status
 */
function backup()
{
    // load config
    $cfgPath = 'cfg.json';
    if (file_exists($cfgPath)) {
        $configuration = json_decode(file_get_contents($cfgPath), true);
        $db_name = $configuration['db_name'];
        $db_user = $configuration['db_user'];
        $db_password = $configuration['db_password'];

        date_default_timezone_set('Australia/Adelaide');
        $now = date('m/d/Y h:i:s a', time());
        printf("backup started at %s\n", $now);

        // $timestamp = date("Ymd_Hi");
        $timestamp = date("Ymd_H");
        $backup_path = $configuration['backup_path'] . $timestamp;
        printf("backup_path: %s\n", $backup_path);

        if (!is_dir($backup_path)) {
            mkdir($backup_path, 0700, true);
        }

        $sql_backup_file = $backup_path . '/' . $timestamp . '_db_backup.sql';

        // mysql backup cmd
        $mysqldump_cmd = 'mysqldump -u' . $db_user . ' -p' . $db_password . ' --databases ' . $db_name . '>' . $sql_backup_file;

//        printf("mysqldump_cmd: %s\n", $mysqldump_cmd);

        // exec mysql backup cmd
        exec($mysqldump_cmd, $output, $mysqldump_cmd_return_value);

        if ($mysqldump_cmd_return_value != 0) {
            printf("execute mysqldump cmd error.\n");
        } else {
            // compress sql file
            if (file_exists($sql_backup_file)) {
                $gzip_cmd = 'gzip ' . $sql_backup_file;
                // exec gzip_cmd
                exec($gzip_cmd, $output, $return_value);
                if ($return_value != 0) {
                    printf("compress sql file error.\n");
                }
            } else {
                printf("sql backup file not found.\n");
            }
        }

        $wordpress_backup_file = $backup_path . '/' . $timestamp . '_wp_backup.tgz';
        $wordpress_root_path = $configuration['wordpress_root_path'];
        printf("wordpress_root_path: %s\n", $wordpress_root_path);
        // wordpress backup cmd
        $wordpress_backup_cmd = 'tar -czf ' . $wordpress_backup_file . ' ' . $wordpress_root_path;

        printf("wordpress_backup_cmd: %s\n", $wordpress_backup_cmd);

        // exec wordpress backup cmd
        exec($wordpress_backup_cmd, $output, $wordpress_backup_cmd_return_value);

        if ($wordpress_backup_cmd_return_value != 0) {
            printf("execute wordpress backup cmd error.\n");
        }

        // both commands are executed successfully
        if ($mysqldump_cmd_return_value == 0 && $wordpress_backup_cmd_return_value == 0) {
            // tar file
            $full_backup_file = './' . $timestamp . '_full_backup.tgz';
            $full_backup_cmd = 'tar -czf ' . $full_backup_file . ' ' . $backup_path;

            printf("full_backup_cmd: %s\n", $full_backup_cmd);

            // exec backup cmd
            exec($full_backup_cmd, $output, $full_backup_cmd_return_value);

            if ($full_backup_cmd_return_value != 0) {
                printf("execute full backup cmd error.\n");
            } else {
                // backup successfully
                // show file size
                printf("full backup file size: %s\n", formatSizeUnits(filesize($full_backup_file)));

                // delete backup dir
                if (!empty($backup_path)) {
                    $delete_backup_dir_cmd = 'rm -rf ' . $backup_path;
                    printf("delete_backup_dir_cmd " . $delete_backup_dir_cmd . "\n");
                    // delete_backup_dir_cmd
                    exec($delete_backup_dir_cmd, $output, $delete_backup_dir_cmd_return_value);

                    if ($delete_backup_dir_cmd_return_value != 0) {
                        printf("execute delete backup dir cmd error.\n");
                    }
                }

                // delete old backups
                $backup_num = $configuration['backup_num'];
                printf("$backup_num: %i\n", $backup_num);

                // delete oldest backups
                $delete_older_backups_cmd = "find . -maxdepth 1 -type f -name \"*full_backup.tgz\" -mtime +".$backup_num." -exec rm -f {} \;";
                printf("delete_older_backups_cmd: %s\n", $delete_older_backups_cmd);

                // exec delete_older_backups_cmd
                exec($delete_older_backups_cmd, $output, $delete_older_backups_cmd_return_value);
                if ($delete_older_backups_cmd_return_value != 0) {
                    printf("execute delete_older_backups cmd error.\n");
                }

                printf("upload backup files to Google Drive.\n");
                // upload to google drive
                uploadFile2Google($timestamp . '_full_backup.tgz', $full_backup_file);

                $now = date('m/d/Y h:i:s a', time());
                printf("backup finished at %s\n\n", $now);
            }
        }
    } else {
        throw new Exception('Config file ' . $cfgPath . ' not found.');
    }
}

backup();