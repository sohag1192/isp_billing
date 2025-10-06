# 🌐 ISP Billing Management System

An advanced **ISP Client Management & Billing System** built with **Pure PHP (PDO)** and **MySQL**, designed for Internet Service Providers to manage clients, routers, packages, invoices, and payments — all in one secure platform.

---

## 🚀 Features

### 🔑 Core Modules
- Client Management (Add/Edit/View)
- Router & OLT Management (MikroTik + VSOL)
- Package & Bandwidth Plans
- Automated Monthly Invoicing
- Payment & Due Tracking System
- Employee / HR Module
- Reseller & Commission System
- Real-time Online/Offline Monitoring

### 💰 Billing & Payment
- Auto-generate invoices on the 1st of each month  
- Carry forward previous dues automatically  
- Supports partial payments, discounts, and online gateways  
- Generate and print payment receipts  
- Export reports in CSV / Excel format  

### 🧠 System Highlights
- Fully procedural PHP (no frameworks)
- PDO-secured database operations  
- Bootstrap 5 + Icons-based responsive UI  
- Bengali/English bilingual interface  
- Light theme, mobile-friendly design  
- VSOL OLT integration (Telnet + SNMP)
- MikroTik RouterOS API integration  

---

## 🧩 Technical Stack

| Component | Technology |
|------------|-------------|
| **Language** | PHP (Procedural) |
| **Database** | MySQL / MariaDB |
| **Frontend** | Bootstrap 5 + Icons |
| **API** | RouterOS API, SNMP, Telnet |
| **PDF Reports** | Dompdf |
| **Notifications** | SMS + Email |
| **Platform** | Localhost (XAMPP) / Ubuntu Server |

---

## ⚙️ Installation Guide

1. **Clone the repository**
   ```bash
   git clone https://github.com/swaponmahmud/isp_billing.git
   ```

2. **Move to your local server directory**
   ```bash
   cd isp_billing
   ```

3. **Create the database**
   - Import the `database.sql` file into your MySQL.

4. **Configure**
   - Edit `/app/config.php` and update your DB credentials.

5. **Run the project**
   - Visit: [http://localhost/isp_billing/public](http://localhost/isp_billing/public)

---

## 👨‍💼 Developer Info

**Author:** Hossain Ahamed  
**Role:** Network System Administrator  
**Company:** SWAPON MULTIMEDIA  
**Location:** Bangladesh  
**Experience:** 8+ years (MikroTik, Cisco, OLT)  

---

## 🏆 License – Mozilla Public License 2.0 (MPL-2.0)

This project is licensed under the **Mozilla Public License 2.0 (MPL-2.0)**.  
You are free to **use, modify, and distribute** this software under the same license.

> 📜 For more details, see the official license: [https://www.mozilla.org/en-US/MPL/2.0/](https://www.mozilla.org/en-US/MPL/2.0/)

---

## 💡 Note
If you build on or redistribute this code, you must:
- Include a copy of the MPL 2.0 license file.  
- Clearly mention your modifications.  
- Keep all original copyright notices.
---------------------------------------------------------------------------------------------------------------------------------------------------------
# 🌐 ISP বিলিং ম্যানেজমেন্ট সিস্টেম

একটি সম্পূর্ণ **ISP ক্লায়েন্ট ম্যানেজমেন্ট ও বিলিং সিস্টেম**, যা তৈরি করা হয়েছে **Pure PHP (PDO)** এবং **MySQL** দিয়ে।  
ইন্টারনেট সার্ভিস প্রোভাইডারদের জন্য এটি এক জায়গায় ক্লায়েন্ট, রাউটার, প্যাকেজ, বিল, পেমেন্ট ও রিসেলার ব্যবস্থাপনার সম্পূর্ণ সমাধান।

---

## 🚀 মূল বৈশিষ্ট্য

### 🔑 প্রধান মডিউল
- ক্লায়েন্ট অ্যাড / এডিট / ভিউ  
- MikroTik ও VSOL OLT ম্যানেজমেন্ট  
- প্যাকেজ ও ব্যান্ডউইথ সেটআপ  
- মাসিক ইনভয়েস অটোমেশন  
- পেমেন্ট ও বকেয়া ট্র্যাকিং  
- এমপ্লয়ি / HR মডিউল  
- রিসেলার কমিশন সিস্টেম  
- অনলাইন / অফলাইন স্ট্যাটাস মনিটরিং  

---

## 💰 বিলিং ও পেমেন্ট
- প্রতি মাসের ১ তারিখে ইনভয়েস স্বয়ংক্রিয়ভাবে তৈরি হয়  
- আগের মাসের বকেয়া স্বয়ংক্রিয়ভাবে যুক্ত হয়  
- আংশিক পেমেন্ট, ডিসকাউন্ট, অনলাইন গেটওয়ে সাপোর্ট  
- ইনভয়েস ও রিসিট প্রিন্ট / PDF এক্সপোর্ট  
- CSV / Excel রিপোর্ট এক্সপোর্ট  

---

## 🧠 সিস্টেম হাইলাইটস
- ফ্রেমওয়ার্ক ছাড়া সম্পূর্ণ Procedural PHP  
- PDO দিয়ে নিরাপদ ডাটাবেজ সংযোগ  
- Bootstrap 5 দিয়ে রেসপনসিভ ও মোবাইল ফ্রেন্ডলি UI  
- বাংলা ও ইংরেজি দুই ভাষার ইন্টারফেস  
- VSOL OLT (Telnet + SNMP) ইন্টিগ্রেশন  
- MikroTik RouterOS API সংযুক্ত  

---

## ⚙️ ইনস্টলেশন গাইড

1. **রিপোজিটরি ক্লোন করুন**
   ```bash
   git clone https://github.com/swaponmahmud/isp_billing.git
   ```

2. **লোকাল সার্ভারে প্রজেক্ট রাখুন**
   ```bash
   cd isp_billing
   ```

3. **ডাটাবেজ তৈরি করুন**
   - `database.sql` ফাইলটি MySQL এ ইমপোর্ট করুন।

4. **কনফিগারেশন আপডেট করুন**
   - `/app/config.php` ফাইলে ডাটাবেজ ইনফো দিন।

5. **প্রজেক্ট রান করুন**
   - [http://localhost/isp_billing/public]

---

## 👨‍💼 ডেভেলপার তথ্য

**নাম:** হোসাইন আহামেদ  
**পদবি:** নেটওয়ার্ক সিস্টেম অ্যাডমিনিস্ট্রেটর  
**প্রতিষ্ঠান:** SWAPON MULTIMEDIA  
**অভিজ্ঞতা:** ৮+ বছর (MikroTik, Cisco, OLT)  

---

## 🏆 লাইসেন্স – Mozilla Public License 2.0 (MPL-2.0)

এই প্রজেক্টটি **Mozilla Public License 2.0 (MPL-2.0)** এর অধীনে প্রকাশিত।  
আপনি এটি **ব্যবহার, পরিবর্তন এবং পুনর্বিতরণ** করতে পারবেন একই লাইসেন্সের অধীনে।

> 📜 বিস্তারিত জানতে দেখুন: [https://www.mozilla.org/en-US/MPL/2.0/](https://www.mozilla.org/en-US/MPL/2.0/)

---

## 💡 নির্দেশনা
যদি আপনি এই কোডে পরিবর্তন করেন বা এটি পুনর্বিতরণ করেন:
- অবশ্যই MPL 2.0 লাইসেন্স ফাইল সংযুক্ত রাখবেন  
- আপনার করা পরিবর্তনগুলি স্পষ্টভাবে উল্লেখ করবেন  
- মূল কপিরাইট নোটিশ অক্ষুণ্ণ রাখবেন  






