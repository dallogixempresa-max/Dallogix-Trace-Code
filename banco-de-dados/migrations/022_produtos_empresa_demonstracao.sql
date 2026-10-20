-- Produtos de demonstração para a Empresa Demonstração.
-- Idempotente: não duplica SKU nem código de barras.
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SUP-FRA-15K', 'SuperCão Frango e Arroz 15kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SUP-FRA-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SUP-CAR-10K', 'SuperCão Carne e Vegetais 10kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SUP-CAR-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'MEG-FIL-03K', 'MegaDog Filhotes Raças Pequenas 3kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'MEG-FIL-03K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'MEG-FIG-15K', 'MegaDog Filhotes Raças Grandes 15kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'MEG-FIG-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'POW-MED-12K', 'PowerNutri Adulto Raças Médias 12kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'POW-MED-12K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'POW-SEN-10K', 'PowerNutri Senior 7+ 10kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'POW-SEN-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ULT-HIP-07K', 'UltraVet Hipoalergênica 7.5kg', 'Ração Terapêutica'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ULT-HIP-07K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ULT-OBS-10K', 'UltraVet Obesidade & Controle 10kg', 'Ração Terapêutica'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ULT-OBS-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ULT-REN-03K', 'UltraVet Renal Care 3kg', 'Ração Terapêutica'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ULT-REN-03K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'BIO-ORG-02K', 'BioDog Orgânica Frango e Aveia 2.5kg', 'Ração Natural'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'BIO-ORG-02K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'BIO-COR-10K', 'BioDog Sabor Cordeiro e Mandioca 10kg', 'Ração Natural'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'BIO-COR-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'NUT-PUP-01K', 'NutriPuppy Leite e Cereais 1kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'NUT-PUP-01K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'MAX-GIG-18K', 'MaxiDog Raças Gigantes 18kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'MAX-GIG-18K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'MIN-COS-01K', 'MiniDog Sabor Costela 1kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'MIN-COS-01K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SAC-FRA-085', 'Sache Cão Gourmet Patê de Frango 85g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SAC-FRA-085');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SAC-CAR-085', 'Sache Cão Gourmet Carne ao Molho 85g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SAC-CAR-085');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'LAT-COR-280', 'Lata DogChef Patê de Cordeiro 280g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'LAT-COR-280');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'LAT-SAL-280', 'Lata DogChef Salmão e Legumes 280g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'LAT-SAL-280');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'FIT-CAS-10K', 'FitDog Light Castrados 10kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'FIT-CAS-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ACT-PER-15K', 'ActiveDog Performance Alta Energia 15kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ACT-PER-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'GOL-PER-15K', 'GoldenPup Sabor Peru e Arroz 15kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'GOL-PER-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'GOL-PFR-03K', 'GoldenPup Raças Pequenas Frango 3kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'GOL-PFR-03K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'VIT-DER-07K', 'VitalDog Dermocare Pele Sensível 7.5kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'VIT-DER-07K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'VIT-ART-10K', 'VitalDog Articulações & Mobilidade 10kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'VIT-ART-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'NAT-GRA-02K', 'NaturePet Grain Free Peixe e Batata Doce 2kg', 'Ração Natural'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'NAT-GRA-02K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'NAT-GRA-10K', 'NaturePet Grain Free Cordeiro 10kg', 'Ração Natural'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'NAT-GRA-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'TOP-PRE-20K', 'TopDog Premium Carne e Frango 20kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'TOP-PRE-20K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'TOP-PRE-10K', 'TopDog Premium Raças Pequenas 10kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'TOP-PRE-10K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ECO-VEG-02K', 'EcoDog Vegetariana 2.5kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ECO-VEG-02K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'PUP-DES-01K', 'PuppyCare Desmame 1kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'PUP-DES-01K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SEN-VIT-07K', 'SeniorPlus Vitalidade 10+ 7.5kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SEN-VIT-07K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'PRI-SAL-12K', 'PrimeDog Salmão Puríssimo 12kg', 'Ração Premium'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'PRI-SAL-12K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'PRI-PIC-15K', 'PrimeDog Picanha e Arroz 15kg', 'Ração Premium'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'PRI-PIC-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SAC-MEG-100', 'Sache MegaDog Molho de Carne 100g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SAC-MEG-100');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SAC-MEG-101', 'Sache MegaDog Molho de Frango 100g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SAC-MEG-101');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ULT-GAS-02K', 'UltraVet Gastrointestinal 2kg', 'Ração Terapêutica'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ULT-GAS-02K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ULT-URI-07K', 'UltraVet Urinária Care 7.5kg', 'Ração Terapêutica'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ULT-URI-07K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'ALP-BUL-15K', 'AlphaDog Bully & Molossos 15kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'ALP-BUL-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'MIN-SHI-02K', 'MiniPaws Shih Tzu & Lhasa 2.5kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'MIN-SHI-02K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'MIN-YOR-02K', 'MiniPaws Yorkshire & Chihuahua 2.5kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'MIN-YOR-02K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'MIN-PUG-02K', 'MiniPaws Pug & Bulldog Francês 2.5kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'MIN-PUG-02K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'PUR-CAR-20K', 'PuroTrato Carne Tradicional 20kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'PUR-CAR-20K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'PUR-FRA-15K', 'PuroTrato Frango e Vegetais 15kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'PUR-FRA-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'LAT-IDO-290', 'Lata Gourmet Cães Idosos 290g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'LAT-IDO-290');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'DOG-TAR-03K', 'DogBalance Controle de Tartaro 3kg', 'Ração Especial'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'DOG-TAR-03K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'DOG-SOD-07K', 'DogBalance Baixo Sódio 7.5kg', 'Ração Terapêutica'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'DOG-SOD-07K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'NUT-COM-15K', 'NutriComplete Todos os Portes 15kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'NUT-COM-15K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'NUT-COM-03K', 'NutriComplete Filhotes 3kg', 'Ração Seca'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'NUT-COM-03K');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SAC-BIO-085', 'Sache BioDog Orgânico Carne e Maçã 85g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SAC-BIO-085');

INSERT INTO produtos (company_id, code, name, category)
SELECT c.id, 'SAC-BIO-086', 'Sache BioDog Orgânico Frango e Cenoura 85g', 'Ração Úmida'
FROM empresas c
WHERE c.name = 'Empresa Demonstração'
  AND NOT EXISTS (SELECT 1 FROM produtos p WHERE p.company_id = c.id AND p.code = 'SAC-BIO-086');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560011'
FROM produtos p
WHERE p.code = 'SUP-FRA-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560011');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560028'
FROM produtos p
WHERE p.code = 'SUP-CAR-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560028');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560035'
FROM produtos p
WHERE p.code = 'MEG-FIL-03K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560035');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560042'
FROM produtos p
WHERE p.code = 'MEG-FIG-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560042');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560059'
FROM produtos p
WHERE p.code = 'POW-MED-12K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560059');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560066'
FROM produtos p
WHERE p.code = 'POW-SEN-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560066');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560073'
FROM produtos p
WHERE p.code = 'ULT-HIP-07K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560073');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560080'
FROM produtos p
WHERE p.code = 'ULT-OBS-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560080');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560087'
FROM produtos p
WHERE p.code = 'ULT-REN-03K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560087');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560094'
FROM produtos p
WHERE p.code = 'BIO-ORG-02K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560094');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560100'
FROM produtos p
WHERE p.code = 'BIO-COR-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560100');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560117'
FROM produtos p
WHERE p.code = 'NUT-PUP-01K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560117');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560124'
FROM produtos p
WHERE p.code = 'MAX-GIG-18K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560124');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560131'
FROM produtos p
WHERE p.code = 'MIN-COS-01K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560131');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560148'
FROM produtos p
WHERE p.code = 'SAC-FRA-085'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560148');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560155'
FROM produtos p
WHERE p.code = 'SAC-CAR-085'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560155');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560162'
FROM produtos p
WHERE p.code = 'LAT-COR-280'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560162');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560179'
FROM produtos p
WHERE p.code = 'LAT-SAL-280'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560179');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560186'
FROM produtos p
WHERE p.code = 'FIT-CAS-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560186');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560193'
FROM produtos p
WHERE p.code = 'ACT-PER-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560193');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560209'
FROM produtos p
WHERE p.code = 'GOL-PER-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560209');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560216'
FROM produtos p
WHERE p.code = 'GOL-PFR-03K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560216');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560223'
FROM produtos p
WHERE p.code = 'VIT-DER-07K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560223');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560230'
FROM produtos p
WHERE p.code = 'VIT-ART-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560230');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560247'
FROM produtos p
WHERE p.code = 'NAT-GRA-02K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560247');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560254'
FROM produtos p
WHERE p.code = 'NAT-GRA-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560254');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560261'
FROM produtos p
WHERE p.code = 'TOP-PRE-20K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560261');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560278'
FROM produtos p
WHERE p.code = 'TOP-PRE-10K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560278');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560285'
FROM produtos p
WHERE p.code = 'ECO-VEG-02K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560285');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560292'
FROM produtos p
WHERE p.code = 'PUP-DES-01K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560292');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560308'
FROM produtos p
WHERE p.code = 'SEN-VIT-07K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560308');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560315'
FROM produtos p
WHERE p.code = 'PRI-SAL-12K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560315');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560322'
FROM produtos p
WHERE p.code = 'PRI-PIC-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560322');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560339'
FROM produtos p
WHERE p.code = 'SAC-MEG-100'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560339');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560346'
FROM produtos p
WHERE p.code = 'SAC-MEG-101'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560346');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560353'
FROM produtos p
WHERE p.code = 'ULT-GAS-02K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560353');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560360'
FROM produtos p
WHERE p.code = 'ULT-URI-07K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560360');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560377'
FROM produtos p
WHERE p.code = 'ALP-BUL-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560377');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560384'
FROM produtos p
WHERE p.code = 'MIN-SHI-02K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560384');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560391'
FROM produtos p
WHERE p.code = 'MIN-YOR-02K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560391');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560407'
FROM produtos p
WHERE p.code = 'MIN-PUG-02K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560407');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560414'
FROM produtos p
WHERE p.code = 'PUR-CAR-20K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560414');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560421'
FROM produtos p
WHERE p.code = 'PUR-FRA-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560421');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560438'
FROM produtos p
WHERE p.code = 'LAT-IDO-290'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560438');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560445'
FROM produtos p
WHERE p.code = 'DOG-TAR-03K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560445');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560452'
FROM produtos p
WHERE p.code = 'DOG-SOD-07K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560452');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560469'
FROM produtos p
WHERE p.code = 'NUT-COM-15K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560469');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560476'
FROM produtos p
WHERE p.code = 'NUT-COM-03K'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560476');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560483'
FROM produtos p
WHERE p.code = 'SAC-BIO-085'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560483');

INSERT INTO codigos_produtos (product_id, barcode)
SELECT p.id, '7891234560490'
FROM produtos p
WHERE p.code = 'SAC-BIO-086'
  AND NOT EXISTS (SELECT 1 FROM codigos_produtos pc WHERE pc.barcode = '7891234560490');

COMMIT;
