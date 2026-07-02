/**
 * @NApiVersion 2.1
 * @NScriptType Restlet
 * @NModuleScope Public
 *
 * DIFOT Saved Search API (Castle). Loads a stored saved search and re-runs it via
 * search.create(filterExpression, columns). Certain 3K-SuiteApp checkbox body
 * fields are not usable as direct search filters under the integration role
 * (SSS_INVALID_SRCH_FILTER), although the UI/Administrator and SuiteQL can use
 * them. We translate those specific checkbox filters into equivalent formulanumeric
 * filters, which reference the field via {field} and are accepted. Results are
 * identical to the saved search. POST { "searchID":"674" } (+ "debug":true).
 *
 * Also supports ad-hoc queries (added for DIFOT de entrega):
 *   POST { "sql": "SELECT ... FROM transaction ..." }         -> N/query.runSuiteQL
 *   POST { "search": { "type":"transaction",
 *                      "filters":[{name,operator,values,formula,join}|[..]],
 *                      "columns":[{name,summary,formula,label,join,func,sort}|"name"] } }
 */
define(['N/search', 'N/query', 'N/log'], function (search, query, log) {

  var CONVERT = { custbody_3k_goas_con_cuestiones: 1, custbody13: 1, custbody18: 1 };

  function transform(expr) {
    var out = [];
    for (var i = 0; i < expr.length; i++) {
      var term = expr[i];
      if (Array.isArray(term) && CONVERT[term[0]]) {
        var want = (term[2] === 'T') ? '1' : '0';
        out.push(["formulanumeric: CASE WHEN {" + term[0] + "}='T' THEN 1 ELSE 0 END", 'equalto', want]);
      } else {
        out.push(term);
      }
    }
    return out;
  }

  function introspect(loaded) {
    var out = { searchType: loaded.searchType, filterExpression: null, columns: [] };
    try { out.filterExpression = loaded.filterExpression; } catch (e) {}
    try {
      var cs = loaded.columns || [];
      for (var i = 0; i < cs.length; i++) {
        var c = cs[i];
        out.columns.push({ name: c.name, join: c.join, summary: c.summary, formula: c.formula, label: c.label });
      }
    } catch (e) {}
    return out;
  }

  function serialize(rows) {
    var data = [];
    for (var r = 0; r < rows.length; r++) {
      var row = rows[r], cols = row.columns, obj = {};
      for (var c = 0; c < cols.length; c++) {
        var col = cols[c];
        var key = col.label || ((col.summary ? col.summary + '_' : '') + (col.formula ? 'formula' + c : col.name) + (col.join ? '_' + col.join : ''));
        try { obj[key] = row.getValue(col); } catch (e) { obj[key] = null; }
        try { var t = row.getText(col); if (t !== null && t !== '') obj[key + '__text'] = t; } catch (e) {}
      }
      data.push(obj);
    }
    return data;
  }

  function run(searchObj) {
    var rs = searchObj.run(), out = [], start = 0, batch;
    do { batch = rs.getRange({ start: start, end: start + 1000 }); start += 1000; out = out.concat(batch); } while (batch.length);
    return out;
  }

  function runSql(sql) {
    var res = query.runSuiteQL({ query: sql });
    var cols = [];
    try { cols = res.columns.map(function (c) { return c.fieldId || c.alias || c.label; }); } catch (e) {}
    return { columns: cols, rows: res.asMappedResults() };
  }

  function toColumn(c) {
    if (typeof c === 'string') return search.createColumn({ name: c });
    var spec = { name: c.name };
    if (c.join) spec.join = c.join;
    if (c.summary) spec.summary = c.summary;
    if (c.formula) spec.formula = c.formula;
    if (c.label) spec.label = c.label;
    if (c.func) spec.function = c.func;
    if (c.sort) spec.sort = c.sort;
    return search.createColumn(spec);
  }

  function toFilter(f) {
    if (Array.isArray(f)) return f;
    return search.createFilter({
      name: f.name, join: f.join, operator: f.operator,
      values: f.values, formula: f.formula
    });
  }

  function runAdhoc(def) {
    var s = search.create({
      type: def.type || 'transaction',
      filters: (def.filters || []).map(toFilter),
      columns: (def.columns || []).map(toColumn)
    });
    return { count: null, results: serialize(run(s)) };
  }

  function post(request) {
    try {
      if (request && request.sql) return runSql(request.sql);
      if (request && request.search) return runAdhoc(request.search);
      var id = request && request.searchID;
      if (!id) return { error: { name: 'INVALID_REQUEST', message: 'Provide searchID, sql, or search.' } };
      var loaded = search.load({ id: id });
      if (request.debug) return { debug: introspect(loaded) };
      try {
        var cols = loaded.columns.slice();
        try { cols.push(search.createColumn({ name: 'internalid', summary: search.Summary.COUNT, label: '__cnt' })); } catch (eC) {}
        var fresh = search.create({ type: loaded.searchType, filters: transform(loaded.filterExpression), columns: cols });
        return { results: serialize(run(fresh)), via: 'transformed' };
      } catch (e) {
        return { error: { name: e.name, message: e.message }, debug: introspect(loaded) };
      }
    } catch (e) {
      log.debug({ title: 'difot_search_api error', details: e });
      return { error: { name: e.name, message: e.message } };
    }
  }

  return { post: post };
});
