-- =====================================================
-- PAYROLL & EXPENSES SCHEMA (CORRECTED)
-- Energy App - Employee Payroll & Expense Management
-- Compatible with Supabase users table (id UUID)
-- =====================================================

-- 1. ALTER USERS TABLE - Add National ID and Salary fields
-- =====================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS national_id VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS monthly_salary DECIMAL(12,2) DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100);
ALTER TABLE users ADD COLUMN IF NOT EXISTS bank_account VARCHAR(50);
ALTER TABLE users ADD COLUMN IF NOT EXISTS kra_pin VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS nhif_number VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS nssf_number VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS employment_date DATE;

-- 2. SALARY ADVANCES TABLE
-- Using UUID for user references (compatible with Supabase auth.users)
-- =====================================================
CREATE TABLE IF NOT EXISTS salary_advances (
    advance_id SERIAL PRIMARY KEY,
    advance_code VARCHAR(20) NOT NULL UNIQUE,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount DECIMAL(12,2) NOT NULL,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_date TIMESTAMP,
    approved_by UUID REFERENCES users(id),
    repayment_month VARCHAR(7), -- Format: YYYY-MM
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected, repaid
    reason TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. PAYROLL TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS payroll (
    payroll_id SERIAL PRIMARY KEY,
    payroll_code VARCHAR(20) NOT NULL UNIQUE,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    pay_period VARCHAR(7) NOT NULL, -- Format: YYYY-MM
    
    -- Earnings
    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    overtime_hours DECIMAL(6,2) DEFAULT 0,
    overtime_pay DECIMAL(12,2) DEFAULT 0,
    allowances DECIMAL(12,2) DEFAULT 0,
    bonuses DECIMAL(12,2) DEFAULT 0,
    gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    
    -- Deductions (Kenya Tax)
    paye DECIMAL(12,2) DEFAULT 0, -- Pay As You Earn
    nhif DECIMAL(12,2) DEFAULT 0, -- National Hospital Insurance Fund
    nssf DECIMAL(12,2) DEFAULT 0, -- National Social Security Fund
    housing_levy DECIMAL(12,2) DEFAULT 0, -- 1.5% of gross
    salary_advance_deduction DECIMAL(12,2) DEFAULT 0,
    other_deductions DECIMAL(12,2) DEFAULT 0,
    total_deductions DECIMAL(12,2) DEFAULT 0,
    
    -- Net Pay
    net_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    
    -- Payment Details
    payment_method VARCHAR(20) DEFAULT 'bank', -- bank, mpesa, cash
    payment_status VARCHAR(20) DEFAULT 'pending', -- pending, processing, paid
    payment_date TIMESTAMP,
    payment_reference VARCHAR(50),
    mpesa_receipt VARCHAR(20),
    
    -- Metadata
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT
);

-- 4. EXPENSES TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS expenses (
    expense_id SERIAL PRIMARY KEY,
    expense_code VARCHAR(20) NOT NULL UNIQUE,
    category VARCHAR(50) NOT NULL, -- fuel_purchase, utilities, maintenance, supplies, salaries, etc.
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    station_id INT REFERENCES stations(station_id),
    vendor_name VARCHAR(100),
    invoice_number VARCHAR(50),
    expense_date DATE NOT NULL,
    due_date DATE,
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, paid
    approved_by UUID REFERENCES users(id),
    approved_date TIMESTAMP,
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT
);

-- 5. VOUCHERS / PAYMENTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS vouchers (
    voucher_id SERIAL PRIMARY KEY,
    voucher_code VARCHAR(20) NOT NULL UNIQUE,
    voucher_type VARCHAR(20) NOT NULL, -- payment, petty_cash, reimbursement
    expense_id INT REFERENCES expenses(expense_id),
    payroll_id INT REFERENCES payroll(payroll_id),
    payee_name VARCHAR(100) NOT NULL,
    payee_type VARCHAR(20), -- supplier, employee, other
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL, -- cash, bank, mpesa, cheque
    bank_name VARCHAR(100),
    account_number VARCHAR(50),
    cheque_number VARCHAR(20),
    mpesa_receipt VARCHAR(20),
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, paid, cancelled
    approved_by UUID REFERENCES users(id),
    approved_date TIMESTAMP,
    paid_by UUID REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT
);

-- 6. EXPENSE CATEGORIES (Optional)
-- =====================================================
CREATE TABLE IF NOT EXISTS expense_categories (
    category_id SERIAL PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default expense categories
INSERT INTO expense_categories (category_name, description) VALUES
('Fuel Purchase', 'Fuel stock purchases from suppliers'),
('Utilities', 'Electricity, Water, Internet bills'),
('Maintenance', 'Equipment and station maintenance'),
('Salaries', 'Employee salaries and wages'),
('Supplies', 'Office and station supplies'),
('Transport', 'Transport and logistics'),
('Marketing', 'Advertising and promotions'),
('Insurance', 'Insurance premiums'),
('Taxes', 'Government taxes and levies'),
('Other', 'Miscellaneous expenses')
ON CONFLICT (category_name) DO NOTHING;

-- 7. INDEXES FOR PERFORMANCE
-- =====================================================
CREATE INDEX IF NOT EXISTS idx_salary_advances_user ON salary_advances(user_id);
CREATE INDEX IF NOT EXISTS idx_salary_advances_status ON salary_advances(status);
CREATE INDEX IF NOT EXISTS idx_salary_advances_month ON salary_advances(repayment_month);

CREATE INDEX IF NOT EXISTS idx_payroll_user ON payroll(user_id);
CREATE INDEX IF NOT EXISTS idx_payroll_period ON payroll(pay_period);
CREATE INDEX IF NOT EXISTS idx_payroll_status ON payroll(payment_status);

CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses(category);
CREATE INDEX IF NOT EXISTS idx_expenses_status ON expenses(status);
CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses(expense_date);

CREATE INDEX IF NOT EXISTS idx_vouchers_type ON vouchers(voucher_type);
CREATE INDEX IF NOT EXISTS idx_vouchers_status ON vouchers(status);
CREATE INDEX IF NOT EXISTS idx_vouchers_date ON vouchers(payment_date);

-- 8. GRANT PERMISSIONS (Supabase RLS)
-- =====================================================
-- Enable Row Level Security
ALTER TABLE salary_advances ENABLE ROW LEVEL SECURITY;
ALTER TABLE payroll ENABLE ROW LEVEL SECURITY;
ALTER TABLE expenses ENABLE ROW LEVEL SECURITY;
ALTER TABLE vouchers ENABLE ROW LEVEL SECURITY;
ALTER TABLE expense_categories ENABLE ROW LEVEL SECURITY;

-- Create policies for authenticated users
CREATE POLICY "Allow authenticated access to salary_advances" ON salary_advances
    FOR ALL USING (auth.role() = 'authenticated');

CREATE POLICY "Allow authenticated access to payroll" ON payroll
    FOR ALL USING (auth.role() = 'authenticated');

CREATE POLICY "Allow authenticated access to expenses" ON expenses
    FOR ALL USING (auth.role() = 'authenticated');

CREATE POLICY "Allow authenticated access to vouchers" ON vouchers
    FOR ALL USING (auth.role() = 'authenticated');

CREATE POLICY "Allow authenticated access to expense_categories" ON expense_categories
    FOR ALL USING (auth.role() = 'authenticated');
