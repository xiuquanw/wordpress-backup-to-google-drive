# usage
```shell
# clone code
git clone https://github.com/xiuquanw/wordpress-backup-to-google-drive
cd wordpress-backup-to-google-drive
composer install

# add config file
vi cfg.json
{
 "db_name":"your wordpress db_name",
 "db_user":"your wordpress db_user",
 "db_password":"your wordpress db_password",
 "wordpress_root_path":"\/var\/www\/xxx",
 "backup_path":".\/",
 "backup_num":4
 }
 
 # run
 php main.php
 
 # add crontab entry
 crontab -e
 ## execute script daily at 2am
 0 2 * * * /bin/sh xxx/wordpress-backup-to-google-drive/backup.sh
```
