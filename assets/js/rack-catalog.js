/**
 * ColdAisle — Vendor rack / cabinet catalog for floor planning.
 *
 * External footprint dimensions (width × depth) used for placement on the plan.
 * Heights are rack units (U). Same vendor + U + width + depth + color are grouped.
 *
 * Sources: vendor product literature (NetShelter SX, SmartRack, Vertiv VR,
 * Dell PowerEdge enclosures, CyberPower CR series, Ubiquiti UniFi racks).
 * Values are typical published external sizes; always verify against the exact SKU BOM.
 */
(function (global) {
  'use strict';

  /** @type {{id:string,name:string,short:string,brandColor:string}[]} */
  const vendors = [
    { id: 'apc', name: 'APC / Schneider Electric', short: 'APC', brandColor: '#3dcd58' },
    { id: 'eaton', name: 'Tripp Lite / Eaton', short: 'Eaton', brandColor: '#ffd100' },
    { id: 'vertiv', name: 'Vertiv', short: 'Vertiv', brandColor: '#e31837' },
    { id: 'dell', name: 'Dell', short: 'Dell', brandColor: '#007db8' },
    { id: 'cyberpower', name: 'CyberPower', short: 'CyberPower', brandColor: '#e31837' },
    { id: 'ubiquiti', name: 'Ubiquiti', short: 'Ubiquiti', brandColor: '#0559c9' },
    { id: 'generic', name: 'Generic / Other', short: 'Generic', brandColor: '#64748b' },
  ];

  /**
   * Raw SKU-level rows (will be grouped).
   * color_hex approximates common finish (black, white, grey).
   */
  const rawSkus = [
    // ——— APC / Schneider NetShelter SX (external W×D) ———
    // Common black finishes
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3100 / SX 42U std', u: 42, w: 600, d: 1070, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3100SP', u: 42, w: 600, d: 1070, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3105 (45U)', u: 45, w: 600, d: 1070, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3107 / 48U 1070', u: 48, w: 600, d: 1070, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3150 / 42U deep', u: 42, w: 600, d: 1200, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3157 / 48U deep', u: 48, w: 600, d: 1200, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3300 / 42U wide', u: 42, w: 750, d: 1070, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3307 / 48U wide', u: 48, w: 750, d: 1070, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3350 / 42U wide deep', u: 42, w: 750, d: 1200, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3357 / 48U wide deep', u: 48, w: 750, d: 1200, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3003 / 12U shallow', u: 12, w: 600, d: 900, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3006 / 18U shallow', u: 18, w: 600, d: 900, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'AR3104 / 24U', u: 24, w: 600, d: 1070, color: '#1a1a1a', colorName: 'Black' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'White 42U 600×1070', u: 42, w: 600, d: 1070, color: '#e8e8e8', colorName: 'White' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'White 42U 600×1200', u: 42, w: 600, d: 1200, color: '#e8e8e8', colorName: 'White' },
    { vendor: 'apc', family: 'NetShelter SX', sku: 'White 48U 600×1200', u: 48, w: 600, d: 1200, color: '#e8e8e8', colorName: 'White' },

    // ——— Tripp Lite / Eaton SmartRack ———
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR42UB / 42U std', u: 42, w: 600, d: 1070, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR42UBDP / 42U deep', u: 42, w: 600, d: 1200, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR48UB / 48U', u: 48, w: 600, d: 1070, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR48UBDP / 48U deep', u: 48, w: 600, d: 1200, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR25UB / 25U', u: 25, w: 600, d: 1070, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR24UB / 24U', u: 24, w: 600, d: 1070, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR42UBWD / 42U wide', u: 42, w: 750, d: 1070, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR42UBWDP / 42U wide deep', u: 42, w: 750, d: 1200, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR18UB / 18U', u: 18, w: 600, d: 900, color: '#1c1c1c', colorName: 'Black' },
    { vendor: 'eaton', family: 'SmartRack', sku: 'SR12UB / 12U', u: 12, w: 600, d: 900, color: '#1c1c1c', colorName: 'Black' },

    // ——— Vertiv VR Rack ———
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3100 / 42U 600×1100', u: 42, w: 600, d: 1100, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3100SP', u: 42, w: 600, d: 1100, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3300 / 42U 800×1100', u: 42, w: 800, d: 1100, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3150 / 42U 600×1200', u: 42, w: 600, d: 1200, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3350 / 42U 800×1200', u: 42, w: 800, d: 1200, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3107 / 48U 600×1100', u: 48, w: 600, d: 1100, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3157 / 48U 600×1200', u: 48, w: 600, d: 1200, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3307 / 48U 800×1100', u: 48, w: 800, d: 1100, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3357 / 48U 800×1200', u: 48, w: 800, d: 1200, color: '#1b1b1b', colorName: 'Black' },
    { vendor: 'vertiv', family: 'VR Rack', sku: 'VR3105 / 45U 600×1100', u: 45, w: 600, d: 1100, color: '#1b1b1b', colorName: 'Black' },

    // ——— Dell PowerEdge rack enclosures ———
    { vendor: 'dell', family: 'PowerEdge Rack', sku: '2420 / 24U 600×1070', u: 24, w: 600, d: 1070, color: '#1f1f1f', colorName: 'Black' },
    { vendor: 'dell', family: 'PowerEdge Rack', sku: '4220 / 42U 600×1070', u: 42, w: 600, d: 1070, color: '#1f1f1f', colorName: 'Black' },
    { vendor: 'dell', family: 'PowerEdge Rack', sku: '4820 / 48U 600×1070', u: 48, w: 600, d: 1070, color: '#1f1f1f', colorName: 'Black' },
    { vendor: 'dell', family: 'PowerEdge Rack', sku: '42U wide 750×1070', u: 42, w: 750, d: 1070, color: '#1f1f1f', colorName: 'Black' },
    { vendor: 'dell', family: 'PowerEdge Rack', sku: '48U wide 750×1070', u: 48, w: 750, d: 1070, color: '#1f1f1f', colorName: 'Black' },
    { vendor: 'dell', family: 'PowerEdge Rack', sku: '42U deep 600×1200', u: 42, w: 600, d: 1200, color: '#1f1f1f', colorName: 'Black' },
    { vendor: 'dell', family: 'PowerEdge Rack', sku: '48U deep 600×1200', u: 48, w: 600, d: 1200, color: '#1f1f1f', colorName: 'Black' },

    // ——— CyberPower enclosures ———
    { vendor: 'cyberpower', family: 'CR Series', sku: 'CR42U11001 / 42U 600×1070', u: 42, w: 600, d: 1070, color: '#222222', colorName: 'Black' },
    { vendor: 'cyberpower', family: 'CR Series', sku: 'CR42U1200 / 42U deep', u: 42, w: 600, d: 1200, color: '#222222', colorName: 'Black' },
    { vendor: 'cyberpower', family: 'CR Series', sku: 'CR48U / 48U 600×1070', u: 48, w: 600, d: 1070, color: '#222222', colorName: 'Black' },
    { vendor: 'cyberpower', family: 'CR Series', sku: 'CR48U deep 600×1200', u: 48, w: 600, d: 1200, color: '#222222', colorName: 'Black' },
    { vendor: 'cyberpower', family: 'CR Series', sku: 'CR24U / 24U', u: 24, w: 600, d: 1070, color: '#222222', colorName: 'Black' },
    { vendor: 'cyberpower', family: 'CR Series', sku: 'CR18U / 18U', u: 18, w: 600, d: 900, color: '#222222', colorName: 'Black' },
    { vendor: 'cyberpower', family: 'CR Series', sku: 'CR12U / 12U', u: 12, w: 600, d: 900, color: '#222222', colorName: 'Black' },

    // ——— Ubiquiti UniFi racks (white/light + grey doors) ———
    { vendor: 'ubiquiti', family: 'UniFi Rack Cabinet', sku: 'UACC-Rack-42U-800-P', u: 42, w: 600, d: 800, color: '#f2f2f2', colorName: 'White / perforated' },
    { vendor: 'ubiquiti', family: 'UniFi Rack Cabinet', sku: 'UACC-Rack-42U-800-G', u: 42, w: 600, d: 800, color: '#c8c8c8', colorName: 'Grey' },
    { vendor: 'ubiquiti', family: 'UniFi Rack Cabinet', sku: 'UACC-Rack-42U-1000-P', u: 42, w: 600, d: 1000, color: '#f2f2f2', colorName: 'White / perforated' },
    { vendor: 'ubiquiti', family: 'UniFi Rack Cabinet', sku: 'UACC-Rack-42U-1000-G', u: 42, w: 600, d: 1000, color: '#c8c8c8', colorName: 'Grey' },
    { vendor: 'ubiquiti', family: 'UniFi Rack Cabinet', sku: '42U deep variant 600×1200', u: 42, w: 600, d: 1200, color: '#f2f2f2', colorName: 'White' },

    // ——— Generic placeholders ———
    { vendor: 'generic', family: 'Standard', sku: '42U 600×1000', u: 42, w: 600, d: 1000, color: '#2d3748', colorName: 'Charcoal' },
    { vendor: 'generic', family: 'Standard', sku: '42U 600×1200', u: 42, w: 600, d: 1200, color: '#2d3748', colorName: 'Charcoal' },
    { vendor: 'generic', family: 'Standard', sku: '48U 600×1200', u: 48, w: 600, d: 1200, color: '#1e3a5f', colorName: 'Navy' },
    { vendor: 'generic', family: 'Standard', sku: '42U 800×1200', u: 42, w: 800, d: 1200, color: '#3b2f2f', colorName: 'Brown-black' },
    { vendor: 'generic', family: 'Standard', sku: '24U 600×1000', u: 24, w: 600, d: 1000, color: '#1a3329', colorName: 'Green-black' },
    { vendor: 'generic', family: 'Standard', sku: '18U 600×900', u: 18, w: 600, d: 900, color: '#2d3748', colorName: 'Charcoal' },
    { vendor: 'generic', family: 'Standard', sku: '12U 600×900', u: 12, w: 600, d: 900, color: '#2d3748', colorName: 'Charcoal' },
  ];

  function groupKey(row) {
    return [row.vendor, row.u, row.w, row.d, row.color.toLowerCase()].join('|');
  }

  /** Group identical footprint+color per vendor into one palette object */
  function buildGrouped() {
    const map = new Map();
    rawSkus.forEach(function (row) {
      const key = groupKey(row);
      if (!map.has(key)) {
        map.set(key, {
          id: key,
          vendor: row.vendor,
          family: row.family,
          u_height: row.u,
          width_mm: row.w,
          depth_mm: row.d,
          color_hex: row.color,
          color_name: row.colorName,
          skus: [],
          families: new Set(),
        });
      }
      const g = map.get(key);
      g.skus.push(row.sku);
      g.families.add(row.family);
      // Prefer shared family name if all match
      if (g.families.size === 1) {
        g.family = row.family;
      } else {
        g.family = Array.from(g.families).join(' / ');
      }
    });

    return Array.from(map.values()).map(function (g) {
      const vendorMeta = vendors.find(function (v) { return v.id === g.vendor; });
      const sizeLabel = g.width_mm + '×' + g.depth_mm + ' mm';
      const name = g.u_height + 'U · ' + sizeLabel + (g.color_name ? ' · ' + g.color_name : '');
      return {
        id: g.id,
        vendor: g.vendor,
        vendor_name: vendorMeta ? vendorMeta.name : g.vendor,
        vendor_short: vendorMeta ? vendorMeta.short : g.vendor,
        family: g.family,
        name: name,
        label: (vendorMeta ? vendorMeta.short + ' ' : '') + name,
        u_height: g.u_height,
        width_mm: g.width_mm,
        depth_mm: g.depth_mm,
        color_hex: g.color_hex,
        color_name: g.color_name,
        skus: g.skus,
        sku_summary: g.skus.length === 1
          ? g.skus[0]
          : (g.skus.length + ' models: ' + g.skus.slice(0, 3).join(', ') + (g.skus.length > 3 ? '…' : '')),
        model_key: g.vendor + '-' + g.u_height + 'u-' + g.width_mm + 'x' + g.depth_mm + '-' + g.color_hex.replace('#', ''),
      };
    }).sort(function (a, b) {
      if (a.vendor !== b.vendor) return a.vendor.localeCompare(b.vendor);
      if (a.u_height !== b.u_height) return a.u_height - b.u_height;
      if (a.width_mm !== b.width_mm) return a.width_mm - b.width_mm;
      return a.depth_mm - b.depth_mm;
    });
  }

  const models = buildGrouped();

  function getVendors() {
    return vendors.slice();
  }

  function getModels(vendorId) {
    if (!vendorId || vendorId === 'all') return models.slice();
    return models.filter(function (m) { return m.vendor === vendorId; });
  }

  function getModelById(id) {
    return models.find(function (m) { return m.id === id; }) || null;
  }

  global.ColdAisleRackCatalog = {
    vendors: vendors,
    models: models,
    getVendors: getVendors,
    getModels: getModels,
    getModelById: getModelById,
  };
  global.WinDCIMRackCatalog = global.ColdAisleRackCatalog; // legacy
})(typeof window !== 'undefined' ? window : globalThis);
