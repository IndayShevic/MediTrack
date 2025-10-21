# 📋 Allocation System Guide

## Overview
The **Allocation Programs** feature is designed specifically for **senior citizens** to receive regular medicine distributions from the barangay health system.

---

## 🎯 Who Can See Allocations?

### Currently Implemented:
The allocation programs you create in the Super Admin panel are **visible to all users** who access the allocations page, BUT they are **intended and designed specifically for senior citizens**.

### How It Should Work (Recommended Implementation):

#### **Option 1: Filter by Age (Automatic)**
```php
// In the resident/bhw allocations view, only show programs for users who are 60+ years old
$user_age = calculate_age($user['birthdate']);
if ($user_age >= 60) {
    // Show available allocation programs
} else {
    // Show message: "Allocation programs are only available for senior citizens (60+)"
}
```

#### **Option 2: Senior Citizen Flag in Database**
```sql
-- Add a column to the residents table
ALTER TABLE residents ADD COLUMN is_senior_citizen BOOLEAN DEFAULT FALSE;

-- Then update based on age
UPDATE residents 
SET is_senior_citizen = TRUE 
WHERE TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) >= 60;
```

Then only display allocations where `is_senior_citizen = TRUE`.

#### **Option 3: Role-Based Access**
- Add a "Senior Citizen" role/status
- Only users with this status can view and claim allocations
- Barangay Health Workers can assign this status

---

## 📦 How Allocation Programs Work

### 1. **Program Creation (Super Admin)**
Super admins create allocation programs with:
- **Program Name**: e.g., "Monthly Hypertension Medicine"
- **Medicine**: Which medicine to allocate
- **Quantity per Senior**: How many units each senior gets
- **Frequency**: Monthly or Quarterly distribution
- **Scope**: Barangay-wide or specific Purok
- **Claim Window**: How many days seniors have to claim (default 14 days)

### 2. **Distribution Flow**

#### Step 1: Program is Active
Once created, the program becomes available.

#### Step 2: Senior Citizens See Available Allocations
When a senior citizen logs in or visits the allocations page, they see:
- Programs they're eligible for based on their barangay/purok
- Current month's allocation status
- Claim deadline

#### Step 3: Claiming Process
Seniors can:
- **View** their allocation details
- **Request** their allocated medicine
- **Track** claim status (pending, approved, claimed)

#### Step 4: BHW Processing
Barangay Health Workers:
- See allocation claims from seniors
- Verify eligibility
- Dispense medicine from inventory
- Mark as "claimed"

#### Step 5: Inventory Deduction
When claimed:
- Medicine quantity is deducted from inventory
- Transaction is logged in `inventory_transactions`
- Stock levels are updated

---

## 🔄 Automatic Distribution Logic

### Monthly Programs
- On the 1st of each month, the system creates new allocation records
- Each eligible senior gets an entry in `allocation_distributions`
- Status starts as "pending"
- After claim window expires, unclaimed allocations become "expired"

### Quarterly Programs
- Distributions happen every 3 months (Jan, Apr, Jul, Oct)
- Same process as monthly but less frequent

---

## 💡 Implementation Recommendations

### For Your System:

1. **Add Age Verification**
   ```php
   // In residents table or user session
   function isEligibleForAllocations($resident) {
       $age = calculateAge($resident['birthdate']);
       return $age >= 60; // Senior citizen threshold
   }
   ```

2. **Create Allocation Distribution Records**
   When a program is set to "monthly", automatically create records for all eligible seniors:
   ```php
   // Run this on the 1st of each month (use cron job)
   function createMonthlyAllocations($program_id) {
       $program = getProgram($program_id);
       $seniors = getSeniorCitizens($program['barangay_id'], $program['purok_id']);
       
       foreach ($seniors as $senior) {
           createAllocationRecord([
               'program_id' => $program_id,
               'resident_id' => $senior['id'],
               'medicine_id' => $program['medicine_id'],
               'quantity_allocated' => $program['quantity_per_senior'],
               'status' => 'pending',
               'claim_deadline' => date('Y-m-d', strtotime('+' . $program['claim_window_days'] . ' days'))
           ]);
       }
   }
   ```

3. **Display in Resident Portal**
   ```php
   // In resident/allocations.php
   if (isEligibleForAllocations($current_user)) {
       $myAllocations = getResidentAllocations($current_user['id']);
       // Show allocations with claim buttons
   } else {
       echo "Allocation programs are only available for senior citizens (60 years and above).";
   }
   ```

4. **BHW Can Process Claims**
   - BHW sees all pending allocation claims
   - Can approve and dispense
   - System deducts from inventory automatically

---

## 📊 Database Structure

### allocation_programs
Stores the program configuration (what you create in Super Admin).

### allocation_distributions
Tracks individual allocations:
- Links program → resident → medicine
- Tracks quantity allocated vs claimed
- Status: pending, claimed, expired
- Claim deadline dates

---

## 🎨 Features Already Implemented

✅ Create allocation programs  
✅ Edit existing programs  
✅ Delete programs  
✅ Set frequency (monthly/quarterly)  
✅ Set scope (barangay/purok)  
✅ Configure claim windows  
✅ Beautiful UI with animations  
✅ Live form preview  
✅ Success/error notifications  

---

## 🔜 Features to Implement

### Next Steps:
1. **Auto-generate distributions** for eligible seniors
2. **Resident allocation view** - show "My Allocations"
3. **Claim button** for residents
4. **BHW approval interface** 
5. **Inventory deduction** on claim
6. **Expiry handling** for unclaimed allocations
7. **Age verification** - only show to 60+ years old
8. **Reports** - allocation utilization, unclaimed rates

---

## ❓ FAQ

**Q: Who should see allocations?**  
A: Only senior citizens (60+ years old) should see and claim allocations.

**Q: Can BHW see allocation programs?**  
A: **YES!** BHWs can see allocation programs assigned to their barangay/purok. They will see:
- All active allocation programs in their area
- Statistics (pending claims, claimed, expired)
- List of pending allocation claims from senior citizens
- They process these claims through the normal medicine requests workflow

**Q: Can we change the age threshold?**  
A: Yes! You can set it to any age (55+, 60+, 65+, etc.)

**Q: What if a medicine runs out?**  
A: The system should check inventory before allowing claims. If stock is insufficient, show "Out of Stock" message.

**Q: Can we have different programs for different conditions?**  
A: Yes! Create multiple programs - one for hypertension, one for diabetes, etc.

**Q: How do we prevent duplicate claims?**  
A: The database has a UNIQUE constraint on (program_id, resident_id, distribution_month) to prevent duplicates.

---

## 🚀 Summary

The allocation system is a **powerful tool for regular medicine distribution to senior citizens**. It automates the process, tracks inventory, and ensures fair distribution based on barangay policies.

**Key Principle**: This is designed for **recurring, scheduled distributions** (monthly/quarterly) rather than one-time requests, specifically targeting **senior citizens** who need regular medication support.

