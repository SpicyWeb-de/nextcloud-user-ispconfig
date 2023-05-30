# User ISPCONFIG

Authenticate nextcloud users against ISPConfig Mailuser API

__NOT ACTIVELY MAINTAINED__

Unfortunatly my personal situation has changed and no longer leaves me time to actively maintain this project.  
If you want to help, you can always send pull requests. I will still review them and publish as updates to the store.

Or, if you are willing to overtake the project as maintainer, please contact me.

## Installation

### Normal installation (recommended)

Just install it from your nextclouds application catalogue.

### Development version

Clone this repository into your nextcloud apps directory:

```bash
cd apps/
git clone https://github.com/SpicyWeb-de/nextcloud-user-ispconfig.git user_ispconfig
```

Install it as usual from CLI or admins app list.

## Configuration

### Prerequisites

This authentication method uses ISPConfigs SOAP API. Thus it requires credentials
for a legitimate remote api user.

In your ISPConfig 3 panel go to `System -> Remote Users` and create a new user 
with permissions for *Customer Functions, Server Functions, E-Mail User Functions*.

Along with that, you have to provide the SOAP API Location and Uri.  
If you didn't modify it, these should be: 

- Location: https://YOUR.PANEL.FQDN:PORT/remote/index.php
- Uri: https://YOUR.PANEL.FQDN:PORT/remote/

### Basic configuration

To finally enable authentication against the ISPConfig 3 API you need to add it 
as user backend to your nextclouds config file in config/config.php.  
Using this basic configuration will allow any mail user to authenticate with
E-Mail address and password and will create a new nextcloud account with default
settings on first login.

```php
<?php
$CONFIG = array(
//  [ ... ],
    'user_backends' => array(
        0 => array(
            'class' => 'OC_User_ISPCONFIG',
            'arguments' =>
                array(
                    0 => 'https://YOUR.PANEL.FQDN:PORT/remote/index.php',
                    1 => 'https://YOUR.PANEL.FQDN:PORT/remote/',
                    2 => 'YOUR_REMOTE_API_USER',
                    3 => 'YOUR_REMOTE_API_PASS',
                ),
        ),
    )
);
```

### Extended configuration

The authentication class takes a 5th argument on index 4, allowing further 
configuration for your needs.

#### Global settings

| Option            | Type      | Default| Description  |
| -------           | -------   | ------ | -------      |
| map_uids          | boolean   | true   | Use uid mappings as described below or fall back to ISPConfig mailuser login |
| allowed_domains   | [string]  | false | Whitelist domains for login. Only accounts from this domains allowed, if set |
| default_quota     | string    | false | Quota description (500 M, 2 G, ...) overriding system default for users authenticated by ISPConfig | 
| default_groups    | [string]  | false | Auto-add new users to these groups on first login                         |
| preferences       | [[string]]| false | Default settings to write for other apps on first login, see [Preferences for other apps](#preferences-for-other-apps)                   |

Example:
```php
<?php
$CONFIG = array(
//  [ ... ],
    'user_backends' => array(
        0 => array(
            'class' => 'OC_User_ISPCONFIG',
            'arguments' =>
                array(
                //  [ ... ],
                    4 => array(
                        'allowed_domains' => array(
                            0 => 'domain-one.net',
                            1 => 'domain-two.com',
                            2 => 'doe.com',
                        ),
                        'default_quota' => "50M",
                        'default_groups' => array('users'),
                    )
                ),
        ),
    )
);
```

#### Per domain settings

As you can override system defaults for this authentication method, you also can 
override per-domain using the `domain_config` option.

| Option        | Type      | Default| Description  |
| -------       | -------   | ------ | -------      |
| quota         | string    | false | Quota description (500 M, 2 G, ...) for users of this domain                  | 
| groups        | [string]  | false | Auto-add new users of this domain to these groups on first login              |
| bare-name     | boolean   | false | Authenticate users of this domain by their mailbox name instead of the regular mail address (only for map_uids == true) |
| uid-prefix    | string    | false | Identify users by prefixed mailbox name instead of regular mail address (only for map_uids == true)       |
| uid-suffix    | string    | false | Identify users by suffixed mailbox name instead of regular mail address (only for map_uids == true)       |
| preferences   | [[string]]| false | Default settings to set for other apps on first login, see [Preferences for other apps](#preferences-for-other-apps)                         |
Example:

```php
<?php
$CONFIG = array(
//  [ ... ],
    'user_backends' => array(
        0 => array(
            'class' => 'OC_User_ISPCONFIG',
            'arguments' =>
                array(
                    //  [ ... ],
                    4 => array(
                        //  [ ... ],
                        'domain_config' => array(
                            'domain-one.net' => array(
                                'quota' => '1G',
                                'groups' => array('users', 'company'),
                                'bare-name' => true,
                            ),
                            'domain-two.com' => array(
                                'quota' => '200M',
                            ),
                            'doe.com' => array(
                                'uid-suffix' => '.doe',
                                'quota' => '2G',
                                'groups' => array('users', 'family')
                            )
                        )
                    )
                ),
        ),
    )
);
```

## About Login Names

There are two main options to configure how your users login to your cloud: 
- using the mail login name as set in ISPConfig (mailbox or alternative loginname if you allow customers to set those)  
  set `"map_uids" => false` in your configuration to use this options  
  Your users have to login using their usual credentials known from logging in to their mailboxes, 
  nextcloud UIDs will be equivalent to ISPConfig login names.
- using email address or mapped usernames
  set `"map_uids" => true` to enable this feature (on by default for legacy installations)  
  Your users can login using their email address or a login name generated by mappings you defined in your config.  
  See detailled description and options for UID mapping below.  
  Nextcloud UIDs will be equivalent to the mapped usernames.

### Username (UID) Mapping

There are three options to map your users mail adresses to cloud login names: *bare-name, uid-prefix, uid-suffix*

Normally users would sign in using their mail address, like *awesomeuser@domain-two.com*.  
This results in a ugly federated cloud ID like *awesomeuser@domain-two.com@your-cloud.fqdn*

Like it? Me neither.

The following options (shown in examples above) are evaluated in this exact order per domain.
The first one wins, the other don't matter.  
__It affects how the nextcloud internal user id is built. So DONT change in production after your first users signed in!__

#### bare-name

Users from domain-one.net are allowed to login with their mailbox name.  
Instead of *big.boss@domain-one.net* your boss uses just *big.boss* as login name.

__Resulting in federated cloud ID *big.boss@your-cloud.fqdn*__

*You can set this option for multiple domains. But be careful to have only trustworthy admins 
configuring accounts for these domains. A user having the same mailbox name for a different
domain could hijack the cloud account.*

#### uid-prefix

Users from a domain with this option set authenticate with their mailbox name prefixed by this string.  
Instead of *user@some-ugly-customer-domain.net* the user signes on with *`prefix-`user*.

__Resulting in federated cloud ID *`prefix-`user@your-cloud.fqdn*__

#### uid-suffix

Users from domain doe.com authenticate with their mailbox name suffixed by *.doe*.  
Instead of *john@doe.com* the user signes on with *john`.doe`*.

__Resulting in federated cloud ID *john`.doe`@your-cloud.fqdn*__

## Preferences for other apps

The preferences key is used to set default preferences for other apps in your cloud.  
It is a 2-dimensional array with the app name as 1st level key, the configkey as 2nd level key 
and finally the value as string (see code example).

Sometimes you need to set explicit preferences for your users for several apps.  
The chat application JSXC for example works great preconfigured, as long as all users have an accounnt on the 
same XMPP host with login name equivalent to their Jabber ID.  
That doesn't work well with username mapping and users from different domains.

Instead, you can look at the table `preferences` in the nextcloud database, extract the config option to set 
for the app and define default preferences to set for every new user global or in domain scope.

mysql> select * from oc_preferences where appid="ojsxc";

| userid  | appid | configkey | configvalue                                                                                                                                                                                                                                                                                                           |
|---------|-------|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| john.doe | ojsxc | options   | {"mam":{"enable":"true"},"loginForm":{"enable":"true","jid":"#user","pass":"#password","onConnecting":"quiet","onConnected":"submit","onAuthFail":"submit","attachIfFound":"true","ifFound":"force","startMinimized":"true"},"xmpp":{"username":"john","domain":"doe.com","resource":"Cloud Chat"}} |

To make it more generic (original contains mailbox and domain name), you can substitude with the following placeholders:

| Placeholder | Value |  |
| --- | --- | --- |
| %UID% | Mapped user id | john.doe |
| %MAILBOX% | Users Mailbox name | john |
| %DOMAIN% | Users Domain name | doe.com |

To auto-configure for example JXSC for your users, you could add the following preferences object either on global or domain scope: 
```php
'preferences' => array(
    // 1st level key: appid of the app
    'ojsxc' => array(
        // 2nd level key: configkey to set for the app
        // and the value to set as string
        'options' => '{"mam":{"enable":"true"},"loginForm":{"enable":"true","jid":"#user","pass":"#password","onConnecting":"quiet","onConnected":"submit","onAuthFail":"submit","attachIfFound":"true","ifFound":"force","startMinimized":"true"},"xmpp":{"username":"%MAILBOX%","domain":"%DOMAIN%","resource":"Family Chat"}}'
    )
),
```  

## Troubleshooting

### Always get 'Invalid Password'

#### Check for php-soap

Check your nextclouds log messages for `ERROR: PHP soap extension is not installed or not enabled`  
If this message occours, ensure you have the PHP Soap extension installed and activated.

You can check, if soap is activated by grepping your etc folder like this (adjust folder name to your environment):  
```bash
grep -r ^extension=soap /etc/php
```

If not activated, make sure you have the soap extension installed and activate it. 

On debian-like systems for example install the package php-soap and activate it afterwards by entering  
```bash
apt-get install php-soap
phpenmod soap
service apache2 restart
```



## Thanks to

- Christian Weiske, UserExternal extension as template for lib/base.php
