-- ============================================================
-- ADD FUEL TYPE SUPPORT TO PUMPS TABLE
-- This migration adds fuel_type_id to pumps so each pump can
-- be assigned a specific fuel type (Diesel, Petrol, Kerosene, etc.)
-- ============================================================

-- 1. CREATE FUEL_TYPES TABLE (if not exists)
CREATE TABLE IF NOT EXISTS public.fuel_types (
    fuel_type_id SERIAL PRIMARY KEY,
    fuel_name VARCHAR(50) NOT NULL UNIQUE,           -- e.g., "Diesel", "Super Petrol", "Regular Petrol"
    fuel_code VARCHAR(10) UNIQUE,                     -- e.g., "DSL", "SP", "RP", "KRS"
    description VARCHAR(255),
    price_per_liter NUMERIC(10, 2) DEFAULT 0,
    color_code VARCHAR(20) DEFAULT '#3B82F6',         -- For UI display
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. INSERT DEFAULT FUEL TYPES
INSERT INTO public.fuel_types (fuel_type_id, fuel_name, fuel_code, description, price_per_liter, color_code, is_active)
VALUES 
    (1, 'Super Petrol', 'SP', 'Premium Super Petrol', 180.50, '#10B981', TRUE),
    (2, 'Diesel', 'DSL', 'Regular Diesel', 165.75, '#F59E0B', TRUE),
    (3, 'Regular Petrol', 'RP', 'Regular Unleaded Petrol', 175.00, '#3B82F6', TRUE),
    (4, 'Kerosene', 'KRS', 'Household Kerosene', 150.00, '#8B5CF6', TRUE),
    (5, 'Premium Petrol', 'PP', 'V-Power Premium', 195.00, '#EF4444', TRUE),
    (6, 'Gas (LPG)', 'LPG', 'Liquefied Petroleum Gas', 2800.00, '#06B6D4', TRUE)
ON CONFLICT (fuel_type_id) DO UPDATE SET
    fuel_name = EXCLUDED.fuel_name,
    fuel_code = EXCLUDED.fuel_code,
    description = EXCLUDED.description,
    price_per_liter = EXCLUDED.price_per_liter,
    color_code = EXCLUDED.color_code;

-- Update sequence to start from next ID
SELECT setval('fuel_types_fuel_type_id_seq', (SELECT COALESCE(MAX(fuel_type_id), 1) FROM public.fuel_types));

-- 3. ADD FUEL_TYPE_ID TO PUMPS TABLE
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_schema = 'public' 
                   AND table_name = 'pumps' 
                   AND column_name = 'fuel_type_id') THEN
        ALTER TABLE public.pumps ADD COLUMN fuel_type_id INTEGER REFERENCES public.fuel_types(fuel_type_id);
        CREATE INDEX IF NOT EXISTS idx_pumps_fuel_type ON public.pumps(fuel_type_id);
    END IF;
END $$;

-- 4. UPDATE EXISTING PUMPS TO HAVE A DEFAULT FUEL TYPE (Super Petrol)
UPDATE public.pumps SET fuel_type_id = 1 WHERE fuel_type_id IS NULL;

-- 5. COMMENTS
COMMENT ON TABLE public.fuel_types IS 'Fuel types available at stations (Diesel, Petrol, Kerosene, etc.)';
COMMENT ON COLUMN public.pumps.fuel_type_id IS 'The type of fuel this pump dispenses';

-- ============================================================
-- VERIFICATION QUERIES (Run these to verify the migration)
-- ============================================================
-- SELECT * FROM public.fuel_types ORDER BY fuel_type_id;
-- SELECT p.pump_id, p.pump_name, ft.fuel_name FROM public.pumps p LEFT JOIN public.fuel_types ft ON p.fuel_type_id = ft.fuel_type_id;
