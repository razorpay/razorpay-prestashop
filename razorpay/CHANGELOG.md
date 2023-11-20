# Changelog

Razorpay Prestashop Plugin Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [2.5.4] - 2023-11-20

### Added
- Blocked currencies KWD, OMR, BHD.

## [2.5.3] - 2023-05-22

### Added
- Bug Fix for module installation issue.

## [2.5.2] - 2023-03-23

### Added
- Support for Prestashop 8.0.x

## [2.5.1] - 2022-12-20

### Added
- Integration_type for checkout instrumentation

## [2.5.0] - 2022-05-26

### Added
- Updated php sdk
- Auto webhook setup support

## [2.4.1] - 2021-06-11

### Changed
- Bug fixed, Duplicate order gets created through webhook

## [2.4.0] - 2020-11-20

### Changed
- Bug fixed
- Added callback url for in app browsers
- Added updated logo

## [2.3.5] - 2020-04-27

### Changed
- Bug fixed

## [2.3.4] - 2020-03-02

### Changed
- Bug fixed related with non SEO freindly URL's

## [2.3.3] - 2020-01-29

### Changed
- Added copy button in backend
- Bug fixed order status

## [2.3.2] - 2019-12-09

### Changed
- Bug fixed for order captured

## [2.3.1] - 2019-12-05

### Changed
- Fixed missing logo in module list under BO

## [2.3.0] - 2019-12-04

### Changed
- Fixed issue related with **'promo code apply/removal'** doesn't refresh the order amount to pay
- Removed the dependency of **'Terms & condition'** for **'Pay'** button display 

## [2.2.1] - 2019-11-18

### Changed
- Use absolute URL for Razorpay logo, keep it updated

## [2.2.0] - 2019-11-18

### Added
- Provided option for payment **'Authorize Only'** or **'Authorize and Capture'**
- Support for Analytics

## [2.1.0] - 2019-07-17

### Added
- Support for Orders API with delayed capture
- Support for order.paid webhook
- Config for webhook enable and secret
- Payments edit to insert Prestashop Order ID in notes
- Support for multicurrency

## [2.0.0] - 2017-08-08

### Added
- Support for Prestashop 1.7.x
- No redirect mode

### Changed
- Orders are not created for payments in this release
- Notes and description does not include Order Id
- Shifts to Razorpay SDK instead of curl

### Removed
- Support for Prestashop 1.6.x
- Support for PHP < 5.6.0
- Theme support. Please use the Razorpay Dashboard for configuring the theme color.

## [1.3.1] - 2016-07-02

### Fixed
- Fixes a minor issue with PHP 5.4

## [1.3.0] - 2016-02-25
### Fixed
- General stability fixes
- Fixes a getIsset related issue

## [1.2.0] - 2016-01-05
### Added
- Theme support. Configurable via the Razorpay module settings screen.

## [1.1.0] - 2015-12-24

### Fixed
- Issues in the handling of script tags in the older releases.

### Added
- Improved Checkout flow.
