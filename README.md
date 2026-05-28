# Asset Management System

## Overview

Asset Management System is a comprehensive solution for managing company assets across multiple branches and factories. The system allows for tracking, inventory, and reporting of various types of assets with support for multi-location management and role-based access control.

### Key Features

- **Multi-branch Support**: Organize assets by different factories and locations
- **Comprehensive Asset Tracking**: Track assets with detailed information including location, status, acquisition value, and book value
- **Inventory Management**: Conduct physical inventory checks with photo documentation
- **QR Code Generation**: Generate QR codes for easy asset identification
- **Role-based Access Control**: Different user roles with appropriate permissions
- **Audit Logging**: Track all changes to assets for compliance purposes
- **LDAP Authentication Support**: Integration with enterprise directory services
- **Super Admin Role**: Dedicated system administrator capability

## Branch/Factory Separation

The system separates assets by plant/factory codes:
- Each asset belongs to a specific plant (`plant_code`)
- Users are assigned to specific plants (`plant_code` in users table)
- Users can only access assets from their assigned plant
- Reports and inventories are organized by plant

## User Roles

The system supports three main roles:
- `admin`: Full access to assets within assigned plant, can manage inventory sessions
- `inventory`: Can participate in inventory sessions, limited modification rights
- `viewer`: Read-only access to assets within assigned plant

## Technical Details

- **Frontend**: HTML, CSS (Tailwind), JavaScript
- **Backend**: PHP 7.2.6+
- **Database**: MySQL 8.0+
- **Authentication**: Session-based with optional LDAP integration
- **Security**: Prepared statements to prevent SQL injection, password hashing, CSRF protection
- **File Uploads**: Secure upload handling with validation
- **Internationalization**: Thai language support

## Maintenance

Regular maintenance tasks include:
- Database backups
- Audit log review
- User account management
- Asset status updates
- Report generation
