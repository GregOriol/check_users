# check_users
Nagios check script to get logged in users from w

## Requires
Nothing special

## Setup
Download check_users zip file or clone it into your target server, then
```$ php composer.phar install```
```$ php composer.phar dump-autoload -o```

and call it remotely with `check_by_ssh`

## Usage
Nagios configuration:
```
define command {
        command_name    check_by_ssh_check_users
        command_line    $USER1$/check_by_ssh -H $HOSTADDRESS$ -p 143 -C "php /home/nagios/check_users/check_users.php --device $ARG1$"
}

define service {
        use                             generic-service
        host_name                       myhost
        service_description             my-service
        check_command                   check_users!excludes
}
```

## Checks performed
Returns the number of logged in users and their names
