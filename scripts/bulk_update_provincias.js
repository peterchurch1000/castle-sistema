/**
 * Salesforce bulk update script - Fix missing Provincia fields
 * Uses Playwright to automate Salesforce UI updates
 * Run: npx playwright test --grep "bulk"
 */

const { chromium } = require('playwright');
const fs = require('fs');
const csv = require('csv-parser');

// Maps postal code to { provincia, localidad }
const POSTAL_CODE_MAP = {
  '1407': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1409': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1425': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1426': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1428': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1429': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1430': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1440': { provincia: 'Capital Federal', localidad: 'Buenos Aires' },
  '1605': { provincia: 'Buenos Aires', localidad: 'San Isidro' },
  '1606': { provincia: 'Buenos Aires', localidad: 'San Isidro' },
  '1607': { provincia: 'Buenos Aires', localidad: 'San Isidro' },
  '1608': { provincia: 'Buenos Aires', localidad: 'San Isidro' },
  '1610': { provincia: 'Buenos Aires', localidad: 'San Isidro' },
  '1620': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1625': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1630': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1636': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1640': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1642': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1644': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1646': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1648': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1650': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1655': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1660': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1665': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1667': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1668': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1670': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1675': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1678': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1680': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1684': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1686': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1688': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1690': { provincia: 'Buenos Aires', localidad: 'San Martín' },
  '1700': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1704': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1706': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1708': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1710': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1712': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1714': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1716': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1718': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1720': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1722': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1725': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1727': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1728': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1731': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1732': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1734': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1735': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1736': { provincia: 'Buenos Aires', localidad: 'La Matanza' },
  '1741': { provincia: 'Buenos Aires', localidad: 'Tres de Febrero' },
  '1742': { provincia: 'Buenos Aires', localidad: 'Tres de Febrero' },
  '1744': { provincia: 'Buenos Aires', localidad: 'Tres de Febrero' },
  '1745': { provincia: 'Buenos Aires', localidad: 'Tres de Febrero' },
  '1746': { provincia: 'Buenos Aires', localidad: 'Tres de Febrero' },
  '1750': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1752': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1754': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1755': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1757': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1758': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1759': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1760': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1761': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1762': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1764': { provincia: 'Buenos Aires', localidad: 'Morón' },
  '1770': { provincia: 'Buenos Aires', localidad: 'Merlo' },
  '1772': { provincia: 'Buenos Aires', localidad: 'Merlo' },
  '1775': { provincia: 'Buenos Aires', localidad: 'Merlo' },
  '1802': { provincia: 'Buenos Aires', localidad: 'Berazategui' },
  '1804': { provincia: 'Buenos Aires', localidad: 'Berazategui' },
  '1806': { provincia: 'Buenos Aires', localidad: 'Berazategui' },
  '1820': { provincia: 'Buenos Aires', localidad: 'Lanús' },
  '1822': { provincia: 'Buenos Aires', localidad: 'Lanús' },
  '1824': { provincia: 'Buenos Aires', localidad: 'Lanús' },
  '1836': { provincia: 'Buenos Aires', localidad: 'Llavallol' },
  '1838': { provincia: 'Buenos Aires', localidad: 'Llavallol' },
  '1842': { provincia: 'Buenos Aires', localidad: 'Florencio Varela' },
  '1846': { provincia: 'Buenos Aires', localidad: 'Florencio Varela' },
  '1852': { provincia: 'Buenos Aires', localidad: 'González Catán' },
  '1870': { provincia: 'Buenos Aires', localidad: 'Wilde' },
  '1875': { provincia: 'Buenos Aires', localidad: 'Wilde' },
  '6000': { provincia: 'Buenos Aires', localidad: 'La Plata' },
  '6700': { provincia: 'Buenos Aires', localidad: 'Luján' },
  '5147': { provincia: 'La Pampa', localidad: 'Santa Rosa' },
  '5000': { provincia: 'Córdoba', localidad: 'Córdoba' },
  '5001': { provincia: 'Córdoba', localidad: 'Córdoba' },
  '2000': { provincia: 'Santa Fe', localidad: 'Rosario' },
  '2001': { provincia: 'Santa Fe', localidad: 'Rosario' },
  '2508': { provincia: 'Santa Fe', localidad: 'Santa Fe' },
};

function getAddressDataFromPostal(postalCode) {
  if (POSTAL_CODE_MAP[postalCode]) return POSTAL_CODE_MAP[postalCode];
  if (POSTAL_CODE_MAP[postalCode.substring(0, 4)]) return POSTAL_CODE_MAP[postalCode.substring(0, 4)];
  if (POSTAL_CODE_MAP[postalCode.substring(0, 3)]) return POSTAL_CODE_MAP[postalCode.substring(0, 3)];
  return null;
}

async function updateAccountProvincias() {
  const browser = await chromium.connect(process.env.PLAYWRIGHT_DEBUG_PORT ? `ws://127.0.0.1:9222` : undefined);
  const context = await browser.newContext();
  const page = await context.newPage();

  // Load CSV
  const accounts = [];
  await new Promise((resolve) => {
    fs.createReadStream('/home/bridge-john/bridge-uploads/1780604851675-4k66e6-AddressProblems.csv')
      .pipe(csv())
      .on('data', (row) => {
        if (row.message.includes('Please enter value(s) for: Provincia')) {
          accounts.push(row);
        }
      })
      .on('end', resolve);
  });

  console.log(`\n📋 Found ${accounts.length} accounts with missing Provincia\n`);

  let success = 0, failed = 0, skipped = 0;

  for (let i = 0; i < Math.min(accounts.length, 50); i++) {
    const account = accounts[i];
    const accountId = account.traceKey;
    const editUrl = `https://castle-global.lightning.force.com/lightning/r/Account/${accountId}/edit`;

    process.stdout.write(`[${i + 1}/${Math.min(50, accounts.length)}] Processing ${accountId}... `);

    try {
      await page.goto(editUrl, { timeout: 10000 });
      await page.waitForLoadState('networkidle', { timeout: 5000 });

      // Get postal code
      const postalCode = await page.getAttribute('input[name*="ShippingPostalCode"]', 'value') ||
                         await page.getAttribute('input[name*="BillingPostalCode"]', 'value');
      const accountName = await page.getAttribute('input[name*="Name"]', 'value') || 'Unknown';

      if (!postalCode) {
        console.log('⊘ (no postal code)');
        skipped++;
        continue;
      }

      const addressData = getAddressDataFromPostal(postalCode);
      if (!addressData) {
        console.log(`⊘ (${postalCode} - unknown)`);
        skipped++;
        continue;
      }

      const { provincia, localidad } = addressData;
      let updated = [];

      // Update Provincia if missing
      const provinciaInput = await page.locator('input[name*="Provincia"]').first();
      const currentProvincia = await provinciaInput.inputValue();
      if (!currentProvincia || currentProvincia === '--Ninguno--') {
        await provinciaInput.click();
        await page.waitForTimeout(300);
        await provinciaInput.fill(provincia);
        await page.waitForTimeout(200);
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(500);
        updated.push('Prov');
      }

      // Update Localidad if missing
      const localidadInput = await page.locator('input[name*="Localidad"], input[placeholder*="Localidad"], input[placeholder*="localidad"]').first();
      if (localidadInput && await localidadInput.isVisible()) {
        const currentLocalidad = await localidadInput.inputValue();
        if (!currentLocalidad) {
          await localidadInput.click();
          await page.waitForTimeout(300);
          await localidadInput.fill(localidad);
          await page.waitForTimeout(200);
          await page.keyboard.press('ArrowDown');
          await page.keyboard.press('Enter');
          await page.waitForTimeout(500);
          updated.push('Loc');
        }
      }

      // Save
      const saveBtn = await page.locator('button:has-text("Save")').first();
      if (await saveBtn.isVisible()) {
        await saveBtn.click();
        await page.waitForTimeout(1000);
        const changes = updated.length > 0 ? updated.join('+') : 'no changes';
        console.log(`✓ ${accountName} (${postalCode} → [${changes}])`);
        success++;
      } else {
        console.log('✗ (save button not found)');
        failed++;
      }
    } catch (error) {
      console.log(`✗ (${error.message.substring(0, 40)})`);
      failed++;
    }
  }

  console.log(`\n📊 Results (first 50):`);
  console.log(`  ✓ Updated: ${success}`);
  console.log(`  ✗ Failed: ${failed}`);
  console.log(`  ⊘ Skipped: ${skipped}`);
  console.log(`  Total: ${accounts.length} accounts need fixing\n`);

  await browser.close();
}

updateAccountProvincias().catch(console.error);
