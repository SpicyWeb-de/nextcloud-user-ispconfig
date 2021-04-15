## [Unreleased]

## 0.4.9
- Requirement set to NC 18-21
- Legacy workaround for default groups not being applied  
  [PR#5](https://github.com/SpicyWeb-de/nextcloud-user-ispconfig/pull/5) @detoxhby
- Update project URLs to GitHub

## 0.4.8
- Requirement set to minimum NC20

## 0.4.7

### Changes
- Requirement set to minimum NC19

## 0.4.6

### Changes
- Requirement set to minimum NC18

### Fixes
- Fixes broken group creation for NC18 -> no longer compartible with NC < 18!  
  [#15](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/15) @quest
- Attempt to fix error in account mapping function
  [#16](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/16) @quest

## 0.4.5

### Changes
- tested and marked for compartibility with NC18 @quest

## 0.4.4

### Changes
- tested and marked for compartibility with NC17 @quest

### Fixes
- Fixes error getting userdata of returning user authenticated by email  
  @quest
- Fixes check for not-always-existing domain config array key  
  [#10](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/10) @Ma


## 0.4.3

### Changes
- [#7](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/7) Tested for NC16, raises max NC version @quest

## 0.4.2

### Changes
- tested and marked for compartibility with NC15
  [#6](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/6) @quest

### Fixes
- Adds not existing groups before adding first user to it, see
  [#6](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/6) @quest

## 0.4.1

### Fixed
- Existing User Lookup crashes authentication on SQLite Installations
  @quest

## 0.4.0

### Added
- Disable UID mapping for Login with original username from ISPConfig by setting `map_uids=false` in authenticator config  
  Enabled by default for legacy installations, don't change in production!  
  [#5](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/5) @quest

### Changes
- Code refactoring, added ISPDomainUser class to handle nextcloud user related data and pass it between methods of the authenticator
  @quest

## 0.3.1

### Fixed
- Login with mapped UID for new users
  [#4](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/4) @quest

## 0.3.0

### Added
- Password change for ISPConfig Mailusers
  [#2](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/2) @quest  
  Remote API user needs permission to *Customer Functions, Server Functions, E-Mail User Functions* to update mailuser passwords

### Changes
- Code refactoring, adds new intermediate abstract class for ISPConfig SOAP API handling

## 0.2.2 - 2018-11-20
### Added
- Add Changelog File
- appinfo: Multilingual description and php-soap dependency
- Possibility to set default preferences in other apps on users first login, see Readme
  [#3](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/3) @quest

## 0.2.1 – 2018-11-17
### Added
- Set new users email in email settings, too
  @quest
- Check for php-soap, log error if not available
  @quest
- Add error handling and understandable messages in admin log for some soap errors
  @quest
  
### Fixed
- Fix error in mapping UID -> E-Mail
  @quest

## 0.2.0 – 2018-11-17
### Added
- Support UID to mail mapping with prefix, suffix or bare-name
  @quest

### Changed
- Delete all information added to DB on first login when user gets deleted
  @quest
- Recognize returning users from DB by UID or mail address
  [#1](https://spicyhub.de/spicy-web/nextcloud-user-ispconfig/issues/1) @quest

### Fixed
- Fix error in mapping UID -> E-Mail
  @quest

## 0.1.0 – 2018-11-13
### Added
- Autenticate users by ISPConfig 3 SOAP api
  @quest
- Global quota and group membership settings for new users
  @quest
- Possibility to set quota and group membership per domain
  @quest
- Possibility to restrict login to specific domains by whitelisting
  @quest
