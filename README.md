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

## Database Schema Summary

The system uses MySQL 8.0+ and includes the following key tables:

### Users Table
Stores user account information with role-based access:
- `id`: Primary key
- `username`: Unique username
- `password`: Encrypted password
- `full_name`: User's full name
- `email`: User's email address
- [role](file:///ireport.kslgroup.com/ireport/kslassets/api/assets.php#L8-L8): User role ('admin', 'inventory', 'viewer')
- `plant_code`: Associated plant/branch code
- `is_active`: Active status flag
- `last_login`: Timestamp of last login

### Plants Table
Defines different factory locations:
- `id`: Primary key
- `plant_code`: Unique plant code (e.g., '1001', '1002')
- `plant_name`: Plant name in Thai (e.g., 'โรงงานสุวรรณภูมิ', 'โรงงานบางนา')
- `is_active`: Active status flag

### Departments Table
Organizes departments within plants:
- `id`: Primary key
- `dept_code`: Department code
- `dept_name`: Department name
- `plant_code`: Associated plant code
- `is_active`: Active status flag

### Asset Classes Table
Categorizes different types of assets:
- `id`: Primary key
- `class_code`: Class code (e.g., 'MCH', 'VEH', 'IT')
- `class_name`: Class name in Thai (e.g., 'เครื่องจักร', 'ยานพาหนะ', 'อุปกรณ์ IT')

### Assets Table
Main table storing asset information:
- `id`: Primary key
- `plant_code`: Associated plant code
- [class_code](file:///ireport.kslgroup.com/ireport/kslassets/pages/reports.php#L24-L24): Asset class code
- `asset_no`: Unique asset number
- `asset_description`: Description of the asset
- `cap_date`: Capitalization date
- `acquis_val`: Acquisition value
- `book_val`: Current book value
- `cost_center`: Cost center code
- `department_code`: Department code
- `department_name`: Department name
- `municipality`: Location/municipality
- `location`: Detailed location information
- `serial_no`: Serial number
- `brand`: Brand name
- `model`: Model number
- `status`: Asset status ('active', 'cancelled', 'disposed', 'transferred')
- `qr_code`: QR code identifier
- `asset_image`: Path to asset image
- `remark`: Additional remarks
- `created_by`: Creator ID
- `updated_by`: Last updater ID

### Inventory Tables
- `inventory_sessions`: Manage inventory counting sessions
- `inventory_results`: Store results of inventory checks

### Supporting Tables
- `asset_images`: Store multiple images per asset
- `audit_logs`: Track all system changes
- `import_logs`: Log asset import activities

## Installation

### Prerequisites

- Web Server (Apache/Nginx)
- PHP 7.2.6+
- MySQL 8.0+
- LDAP support (optional, for enterprise integration)

### Installation Steps

1. Clone or copy the project files to your web server directory:
   ```bash
   git clone https://github.com/your-repo/asset-management-system.git
   ```

2. Create a MySQL database:
   ```sql
   CREATE DATABASE asset_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. Import the database schema from [asset.sql](file:///ireport.kslgroup.com/ireport/kslassets/asset.sql):
   ```bash
   mysql -u root -p asset_db < asset.sql
   ```

4. Configure the application by editing [config.php](file:///ireport.kslgroup.com/ireport/kslassets/config.php):
   - Update database credentials ([DB_HOST](file:///ireport.kslgroup.com/ireport/kslassets/config.php#L7-L7), [DB_USER](file:///ireport.kslgroup.com/ireport/kslassets/config.php#L8-L8), [DB_PASS](file:///ireport.kslgroup.com/ireport/kslassets/config.php#L9-L9), [DB_NAME](file:///ireport.kslgroup.com/ireport/kslassets/config.php#L10-L10))
   - Adjust [APP_URL](file:///ireport.kslgroup.com/ireport/kslassets/config.php#L16-L16) to match your server's URL
   - Set timezone if needed

5. Run the installation script by accessing `install.php` in your browser to:
   - Create the database schema
   - Set up the super admin account
   - Configure LDAP authentication (optional)

6. Set up web server virtual host or directory with appropriate permissions

7. Access the application via web browser

### Initial Login Credentials

Default admin user:
- Username: `admin`
- Password: `admin1234`

## Super Admin Design

The system has been designed with a super admin concept in mind:

1. The super admin is created during the installation process
2. A super admin has unrestricted access across all plants and departments
3. Super admin can manage users across all branches
4. Super admin can view and modify all assets regardless of plant restrictions

During installation, you will create the super admin user account which will have a NULL plant_code, allowing access across all plants.

## LDAP Authentication Implementation

The system supports both local authentication and LDAP authentication. During installation, you can configure LDAP settings that will allow users to log in using their corporate credentials.

The authentication logic in [helper/auth.php](file:///ireport.kslgroup.com/ireport/kslassets/helper/auth.php) first attempts LDAP authentication and falls back to local authentication if LDAP is not enabled or if the LDAP authentication fails.

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

## License

This project is proprietary software developed for KSL Group.