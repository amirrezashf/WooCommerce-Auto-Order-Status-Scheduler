# WooCommerce Auto Order Status Scheduler

Automatically schedule WooCommerce order status changes after a specific period of time. Define a target status, choose a delay, and let the plugin update orders automatically.

---

## Description

WooCommerce Auto Order Status Scheduler helps automate repetitive order management tasks by allowing administrators to schedule future status changes for individual orders.

Instead of manually updating order statuses after a few hours or days, you can define the target status and execution time once and let the plugin handle the process automatically.

The plugin includes order-level scheduling controls, status restrictions, execution management, and a dedicated administration page for monitoring all scheduled changes.

---

## Features

- Schedule automatic order status changes
- Select target WooCommerce status
- Set delay by minutes, hours, days, weeks, or months
- Automatic background execution
- Dedicated order metabox
- Scheduled orders management page
- Restrict allowed source statuses
- Block specific destination statuses
- Store creator information and timestamps
- WooCommerce compatible
- Lightweight architecture
- WordPress native scheduling support

---

## Requirements

- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- PHP 7.4 or higher

---

## Developer Documentation

### Main Meta Keys

_fa_auto_status_enabled

_fa_auto_status_quantity

_fa_auto_status_unit

_fa_auto_status_target

_fa_auto_status_run_at_utc

_fa_auto_status_run_at_local

_fa_auto_status_set_by

_fa_auto_status_set_by_name

_fa_auto_status_set_at

### Main Option

fa_auto_status_settings

### Scheduled Actions

fa_run_auto_order_status_change

fa_run_auto_order_status_change_fallback

### Settings Structure

array(
    'allowed_from_statuses' => array(),
    'blocked_to_statuses'   => array(),
)

---

## Changelog

### 1.0.0

- Initial release
- Automatic order status scheduling
- Scheduled orders management page
- Status restriction settings
- Order scheduling metabox
- Background execution system

---
# فارسی

## زمان‌بندی خودکار تغییر وضعیت سفارشات ووکامرس

این افزونه امکان زمان‌بندی تغییر وضعیت سفارشات ووکامرس را فراهم می‌کند. مدیر فروشگاه می‌تواند برای هر سفارش مشخص کند که پس از مدت زمان مشخص، وضعیت سفارش به صورت خودکار تغییر کند.

---

## توضیحات

این افزونه برای کاهش کارهای تکراری مدیریت سفارشات طراحی شده است و به شما اجازه می‌دهد بدون نیاز به تغییر دستی وضعیت سفارش‌ها، فرآیندهای مختلف فروشگاه را خودکار کنید.

برای هر سفارش می‌توانید وضعیت مقصد، زمان اجرا و بازه زمانی موردنظر را تعیین کنید تا افزونه در زمان مشخص شده عملیات تغییر وضعیت را انجام دهد.

همچنین تمامی سفارشات زمان‌بندی شده از طریق یک صفحه مدیریتی قابل مشاهده و مدیریت هستند.

---

## امکانات

- زمان‌بندی تغییر خودکار وضعیت سفارش
- انتخاب وضعیت مقصد سفارش
- تعیین زمان اجرا بر اساس دقیقه، ساعت، روز، هفته یا ماه
- اجرای خودکار در پس‌زمینه
- متاباکس اختصاصی در صفحه سفارش
- صفحه مدیریت سفارشات زمان‌بندی شده
- محدود کردن وضعیت‌های مجاز برای شروع زمان‌بندی
- مسدود کردن وضعیت‌های مقصد خاص
- ثبت اطلاعات کاربر ایجادکننده زمان‌بندی
- سازگار با ووکامرس
- سبک و بهینه
- استفاده از سیستم زمان‌بندی داخلی وردپرس

---

## موارد استفاده

- تغییر خودکار سفارش از «در حال انجام» به «تکمیل شده»
- خودکارسازی فرآیندهای ارسال و تحویل
- مدیریت سفارشات خدماتی و اشتراکی
- کاهش نیاز به تغییر دستی وضعیت سفارش‌ها
- ایجاد گردش کار خودکار برای فروشگاه

---

## مستندات توسعه

### متاهای اصلی سفارش

_fa_auto_status_enabled

_fa_auto_status_quantity

_fa_auto_status_unit

_fa_auto_status_target

_fa_auto_status_run_at_utc

_fa_auto_status_run_at_local

_fa_auto_status_set_by

_fa_auto_status_set_by_name

_fa_auto_status_set_at

### تنظیمات اصلی

fa_auto_status_settings

### هوک‌های زمان‌بندی

fa_run_auto_order_status_change

fa_run_auto_order_status_change_fallback

### ساختار تنظیمات

array(
    'allowed_from_statuses' => array(),
    'blocked_to_statuses'   => array(),
)

---

## تغییرات نسخه

### 1.0.0

- انتشار اولیه افزونه
- امکان زمان‌بندی تغییر وضعیت سفارش
- صفحه مدیریت سفارشات زمان‌بندی شده
- تنظیم وضعیت‌های مجاز و غیرمجاز
- متاباکس اختصاصی سفارش
- اجرای خودکار تغییر وضعیت در زمان تعیین شده
