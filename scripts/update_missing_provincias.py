#!/usr/bin/env python3
"""
Salesforce bulk update script to fix missing Provincia fields.
Maps postal codes to Argentine provinces and updates accounts.
"""

import csv
import json
import requests
import sys
from typing import Dict, Optional

# Postal code to Province mapping
POSTAL_CODE_MAP = {
    # Capital Federal (1000-1440)
    '1000': 'Capital Federal', '1001': 'Capital Federal', '1002': 'Capital Federal',
    '1010': 'Capital Federal', '1020': 'Capital Federal', '1030': 'Capital Federal',
    '1040': 'Capital Federal', '1050': 'Capital Federal', '1060': 'Capital Federal',
    '1070': 'Capital Federal', '1100': 'Capital Federal', '1110': 'Capital Federal',
    '1120': 'Capital Federal', '1130': 'Capital Federal', '1140': 'Capital Federal',
    '1150': 'Capital Federal', '1160': 'Capital Federal', '1170': 'Capital Federal',
    '1200': 'Capital Federal', '1210': 'Capital Federal', '1220': 'Capital Federal',
    '1230': 'Capital Federal', '1240': 'Capital Federal', '1250': 'Capital Federal',
    '1260': 'Capital Federal', '1270': 'Capital Federal', '1280': 'Capital Federal',
    '1285': 'Capital Federal', '1290': 'Capital Federal', '1300': 'Capital Federal',
    '1310': 'Capital Federal', '1320': 'Capital Federal', '1330': 'Capital Federal',
    '1335': 'Capital Federal', '1340': 'Capital Federal', '1350': 'Capital Federal',
    '1360': 'Capital Federal', '1370': 'Capital Federal', '1375': 'Capital Federal',
    '1380': 'Capital Federal', '1390': 'Capital Federal', '1407': 'Capital Federal',
    '1409': 'Capital Federal', '1425': 'Capital Federal', '1426': 'Capital Federal',
    '1428': 'Capital Federal', '1429': 'Capital Federal', '1430': 'Capital Federal',
    '1440': 'Capital Federal',

    # Buenos Aires Province (1500-1999, 6000+)
    '1500': 'Buenos Aires', '1501': 'Buenos Aires', '1502': 'Buenos Aires',
    '1605': 'Buenos Aires', '1606': 'Buenos Aires', '1607': 'Buenos Aires',
    '1608': 'Buenos Aires', '1610': 'Buenos Aires', '1620': 'Buenos Aires',
    '1625': 'Buenos Aires', '1627': 'Buenos Aires', '1628': 'Buenos Aires',
    '1630': 'Buenos Aires', '1636': 'Buenos Aires', '1640': 'Buenos Aires',
    '1642': 'Buenos Aires', '1644': 'Buenos Aires', '1646': 'Buenos Aires',
    '1648': 'Buenos Aires', '1650': 'Buenos Aires', '1655': 'Buenos Aires',
    '1660': 'Buenos Aires', '1665': 'Buenos Aires', '1667': 'Buenos Aires',
    '1668': 'Buenos Aires', '1670': 'Buenos Aires', '1675': 'Buenos Aires',
    '1678': 'Buenos Aires', '1680': 'Buenos Aires', '1684': 'Buenos Aires',
    '1686': 'Buenos Aires', '1688': 'Buenos Aires', '1690': 'Buenos Aires',
    '1700': 'Buenos Aires', '1704': 'Buenos Aires', '1706': 'Buenos Aires',
    '1708': 'Buenos Aires', '1710': 'Buenos Aires', '1712': 'Buenos Aires',
    '1714': 'Buenos Aires', '1716': 'Buenos Aires', '1718': 'Buenos Aires',
    '1720': 'Buenos Aires', '1722': 'Buenos Aires', '1725': 'Buenos Aires',
    '1727': 'Buenos Aires', '1728': 'Buenos Aires', '1731': 'Buenos Aires',
    '1732': 'Buenos Aires', '1734': 'Buenos Aires', '1735': 'Buenos Aires',
    '1736': 'Buenos Aires', '1741': 'Buenos Aires', '1742': 'Buenos Aires',
    '1744': 'Buenos Aires', '1745': 'Buenos Aires', '1746': 'Buenos Aires',
    '1750': 'Buenos Aires', '1752': 'Buenos Aires', '1754': 'Buenos Aires',
    '1755': 'Buenos Aires', '1757': 'Buenos Aires', '1758': 'Buenos Aires',
    '1759': 'Buenos Aires', '1760': 'Buenos Aires', '1761': 'Buenos Aires',
    '1762': 'Buenos Aires', '1764': 'Buenos Aires', '1770': 'Buenos Aires',
    '1772': 'Buenos Aires', '1775': 'Buenos Aires', '1802': 'Buenos Aires',
    '1804': 'Buenos Aires', '1806': 'Buenos Aires', '1820': 'Buenos Aires',
    '1822': 'Buenos Aires', '1824': 'Buenos Aires', '1836': 'Buenos Aires',
    '1838': 'Buenos Aires', '1842': 'Buenos Aires', '1846': 'Buenos Aires',
    '1852': 'Buenos Aires', '1870': 'Buenos Aires', '1875': 'Buenos Aires',
    '6000': 'Buenos Aires', '6001': 'Buenos Aires', '6002': 'Buenos Aires',
    '6003': 'Buenos Aires', '6004': 'Buenos Aires', '6005': 'Buenos Aires',
    '6006': 'Buenos Aires', '6007': 'Buenos Aires', '6008': 'Buenos Aires',
    '6009': 'Buenos Aires', '6010': 'Buenos Aires', '6011': 'Buenos Aires',
    '6012': 'Buenos Aires', '6013': 'Buenos Aires', '6014': 'Buenos Aires',
    '6015': 'Buenos Aires', '6016': 'Buenos Aires', '6017': 'Buenos Aires',
    '6018': 'Buenos Aires', '6019': 'Buenos Aires', '6020': 'Buenos Aires',
    '6100': 'Buenos Aires', '6200': 'Buenos Aires', '6300': 'Buenos Aires',
    '6400': 'Buenos Aires', '6500': 'Buenos Aires', '6600': 'Buenos Aires',
    '6700': 'Buenos Aires', '6800': 'Buenos Aires', '6900': 'Buenos Aires',

    # Córdoba
    '5000': 'Córdoba', '5001': 'Córdoba', '5002': 'Córdoba', '5003': 'Córdoba',
    '5004': 'Córdoba', '5005': 'Córdoba', '5006': 'Córdoba', '5007': 'Córdoba',
    '5008': 'Córdoba', '5009': 'Córdoba', '5010': 'Córdoba', '5011': 'Córdoba',
    '5100': 'Córdoba', '5140': 'Córdoba', '5150': 'Córdoba', '5160': 'Córdoba',
    '5170': 'Córdoba', '5180': 'Córdoba', '5190': 'Córdoba',

    # Santa Fe
    '2000': 'Santa Fe', '2001': 'Santa Fe', '2002': 'Santa Fe', '2003': 'Santa Fe',
    '2004': 'Santa Fe', '2005': 'Santa Fe', '2006': 'Santa Fe', '2007': 'Santa Fe',
    '2008': 'Santa Fe', '2009': 'Santa Fe', '2010': 'Santa Fe', '2011': 'Santa Fe',
    '2012': 'Santa Fe', '2100': 'Santa Fe', '2500': 'Santa Fe', '2508': 'Santa Fe',
    '2600': 'Santa Fe', '2700': 'Santa Fe', '2800': 'Santa Fe', '2900': 'Santa Fe',

    # La Pampa
    '5500': 'La Pampa', '5501': 'La Pampa', '5502': 'La Pampa', '5503': 'La Pampa',
    '5504': 'La Pampa', '5505': 'La Pampa', '5506': 'La Pampa', '5507': 'La Pampa',
    '5508': 'La Pampa', '5509': 'La Pampa', '5510': 'La Pampa', '5511': 'La Pampa',
    '5600': 'La Pampa', '5700': 'La Pampa', '5800': 'La Pampa', '5900': 'La Pampa',
    '5147': 'La Pampa',
}


class SalesforceAPI:
    """Handles Salesforce API authentication and operations."""

    def __init__(self, instance_url: str, client_id: str, client_secret: str):
        self.instance_url = instance_url
        self.client_id = client_id
        self.client_secret = client_secret
        self.access_token = None
        self.authenticate()

    def authenticate(self):
        """Get access token using OAuth password flow."""
        auth_url = f"{self.instance_url}/services/oauth2/token"

        # Use username/password flow - need credentials
        payload = {
            'grant_type': 'client_credentials',
            'client_id': self.client_id,
            'client_secret': self.client_secret,
        }

        try:
            response = requests.post(auth_url, data=payload)
            response.raise_for_status()
            self.access_token = response.json()['access_token']
            print(f"✓ Authenticated to Salesforce")
        except requests.exceptions.RequestException as e:
            print(f"✗ Authentication failed: {e}")
            sys.exit(1)

    def get_account(self, account_id: str) -> Optional[Dict]:
        """Fetch account details."""
        url = f"{self.instance_url}/services/data/v59.0/sobjects/Account/{account_id}"
        headers = {'Authorization': f'Bearer {self.access_token}'}

        try:
            response = requests.get(url, headers=headers)
            response.raise_for_status()
            return response.json()
        except requests.exceptions.RequestException as e:
            print(f"✗ Failed to fetch account {account_id}: {e}")
            return None

    def update_account(self, account_id: str, data: Dict) -> bool:
        """Update account fields."""
        url = f"{self.instance_url}/services/data/v59.0/sobjects/Account/{account_id}"
        headers = {
            'Authorization': f'Bearer {self.access_token}',
            'Content-Type': 'application/json'
        }

        try:
            response = requests.patch(url, json=data, headers=headers)
            response.raise_for_status()
            return True
        except requests.exceptions.RequestException as e:
            print(f"✗ Failed to update account {account_id}: {e}")
            return False


def get_provincia_from_postal(postal_code: str) -> Optional[str]:
    """Map postal code to province."""
    # Try exact match first
    if postal_code in POSTAL_CODE_MAP:
        return POSTAL_CODE_MAP[postal_code]

    # Try first 4 digits
    if postal_code[:4] in POSTAL_CODE_MAP:
        return POSTAL_CODE_MAP[postal_code[:4]]

    # Try first 3 digits
    if postal_code[:3] in POSTAL_CODE_MAP:
        return POSTAL_CODE_MAP[postal_code[:3]]

    return None


def main():
    # Load credentials from environment
    import os
    from dotenv import load_dotenv

    load_dotenv('/var/www/castle-sistema/.env')

    instance_url = os.getenv('SF_INSTANCE_URL')
    client_id = os.getenv('SF_CLIENT_ID')
    client_secret = os.getenv('SF_CLIENT_SECRET')

    if not all([instance_url, client_id, client_secret]):
        print("✗ Missing Salesforce credentials in .env")
        sys.exit(1)

    # Initialize API
    sf = SalesforceAPI(instance_url, client_id, client_secret)

    # Read CSV
    csv_path = '/home/bridge-john/bridge-uploads/1780604851675-4k66e6-AddressProblems.csv'

    try:
        with open(csv_path, 'r') as f:
            reader = csv.DictReader(f)
            accounts = [row for row in reader if 'Please enter value(s) for: Provincia' in row['message']]
    except Exception as e:
        print(f"✗ Failed to read CSV: {e}")
        sys.exit(1)

    print(f"\n📋 Found {len(accounts)} accounts with missing Provincia")
    print("=" * 60)

    success_count = 0
    failed_count = 0
    skipped_count = 0

    for i, account in enumerate(accounts, 1):
        account_id = account['traceKey']

        # Fetch account
        account_data = sf.get_account(account_id)
        if not account_data:
            failed_count += 1
            continue

        # Get postal code
        postal_code = account_data.get('ShippingPostalCode') or account_data.get('BillingPostalCode')
        account_name = account_data.get('Name', 'Unknown')

        if not postal_code:
            print(f"[{i}/{len(accounts)}] ⊘ {account_name} - No postal code found")
            skipped_count += 1
            continue

        # Map to province
        provincia = get_provincia_from_postal(postal_code)
        if not provincia:
            print(f"[{i}/{len(accounts)}] ⊘ {account_name} ({postal_code}) - Unknown postal code")
            skipped_count += 1
            continue

        # Update account
        update_data = {'ATV_Provincia__c': provincia}
        if sf.update_account(account_id, update_data):
            print(f"[{i}/{len(accounts)}] ✓ {account_name} ({postal_code} → {provincia})")
            success_count += 1
        else:
            failed_count += 1

    # Summary
    print("=" * 60)
    print(f"\n📊 Results:")
    print(f"  ✓ Updated: {success_count}")
    print(f"  ✗ Failed: {failed_count}")
    print(f"  ⊘ Skipped: {skipped_count}")
    print(f"  Total processed: {len(accounts)}\n")


if __name__ == '__main__':
    main()
