# Implementation Plan: Direct P2P Payments

## Overview
The platform currently uses a centralized order model (unified cart, single payment, dispatch to HQ). To implement the Direct P2P Payment System (Option 1), we need to transition to a model where the buyer pays the seller directly via UPI or Bank Transfer.

This requires three major architectural changes:
1. **Splitting Orders by Seller**: A single cart checkout must generate multiple orders if items belong to different sellers.
2. **Payment Tracking**: Orders need to track `payment_status`, `transaction_id`, and `seller_id`.
3. **P2P Verification Flow**: The buyer must be able to view the seller's payment details and upload proof of payment, and the seller must be able to verify and mark the order as paid.

---

## 1. Database Schema Updates
We will create a script `migrate_p2p.php` to run the following alterations:
```sql
-- Add seller context and payment tracking to orders
ALTER TABLE `orders` 
ADD COLUMN `seller_id` INT DEFAULT NULL,
ADD COLUMN `payment_method` ENUM('cod', 'upi', 'bank_transfer') DEFAULT 'upi',
ADD COLUMN `payment_status` ENUM('pending', 'verifying', 'confirmed', 'failed') DEFAULT 'pending',
ADD COLUMN `transaction_id` VARCHAR(100) DEFAULT NULL,
ADD COLUMN `payment_proof` VARCHAR(255) DEFAULT NULL;
```
*Note: Existing orders will have `seller_id = NULL` and `payment_status = pending`.*

## 2. Component: Checkout Flow
#### [MODIFY] [checkout.php](file:///d:/Software/htdocs/REDLINE/checkout.php)
- Modify the cart processing logic to **group cart items by `seller_id`**.
- Instead of inserting a single `order`, iterate through the grouped items and `INSERT INTO orders` for *each seller*, generating a separate order ID for each.
- In the UI, simplify the "Payment Method" dropdown to focus on "Direct UPI/Bank Transfer (P2P)".
- Redirect to a new `checkout_success.php` (or modify [order_success.php](file:///d:/Software/htdocs/REDLINE/order_success.php)) showing all generated Order IDs.

## 3. Component: Buyer Payment Flow
#### [NEW] `pay_order.php`
- Create a new page where buyers are redirected after checkout (or from their order history).
- This page takes `?order_id=X`.
- It fetches the **Seller's `upi_id` and `bank_details`** and displays them to the buyer.
- Provides a form for the buyer to enter the `transaction_id` (UTR number) and optionally upload a `payment_proof` screenshot.
- Submitting the form changes the `payment_status` to `verifying`.

#### [MODIFY] [order_view.php](file:///d:/Software/htdocs/REDLINE/order_view.php) (Buyer Order History)
- Add a "Pay Now" button for orders with `payment_status = pending`.
- Show "Pending Verification" badge when `verifying`.

## 4. Component: Seller Verification Flow
#### [MODIFY] [seller_dashboard/orders.php](file:///d:/Software/htdocs/REDLINE/seller_dashboard/orders.php)
- Modify the SQL query to fetch orders by `seller_id` directly from the `orders` table (for new orders) instead of relying solely on `order_items`.
- Update the UI to show `payment_status` prominently.
- If an order is `verifying`, show a **"Verify Payment"** button that reveals the Transaction ID/Proof.
- Provide "Confirm Received" (changes status to `confirmed`) and "Reject Payment" options.
- Update the info banner to clarify the P2P shipping model (seller ships directly to buyer after payment confirmation).

---

## Verification Plan
1. Run the database migration.
2. Log in as a Buyer, add items from *two different sellers* to the cart.
3. Complete checkout and verify that *two separate orders* are created.
4. Navigate to the buyer's order history and click "Pay Now" on one of the orders.
5. In `pay_order.php`, view the seller's UPI, enter a mock Transaction ID, and submit.
6. Log in as the corresponding Seller, navigate to [seller_dashboard/orders.php](file:///d:/Software/htdocs/REDLINE/seller_dashboard/orders.php).
7. Verify the order is marked "Payment Verifying". Click "Confirm Received".
8. Ensure the order status updates successfully to `confirmed`.
