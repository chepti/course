/**
 * מחולל מרחבי 360° — Google Apps Script Backend
 * שומר פרויקטים ב-Google Sheet ותמונות ב-Google Drive
 */

var CONFIG = {
  SHEET_NAME: 'Projects',
  DRIVE_FOLDER_NAME: '360-Environments',
  WEB_APP_URL: 'https://script.google.com/a/macros/jerschools.org.il/s/AKfycbwg3LisOKMy1MPYclGzsvbXwudELNus7xhXtloBb3hUgjZccR4MCgXadUFbBhJJLtZebg/exec',
  DRIVE_FOLDER_ID: '',
  HEADERS: ['id', 'title', 'created_at', 'updated_at', 'image1_url', 'image2_url', 'active_image', 'hotspots_json', 'edit_token', 'view_url', 'edit_url', 'copy_url', 'image1_thumb_url', 'creator_name', 'creator_info', 'hide_from_catalog', 'opening_message_json', 'enforce_order']
};

function doGet(e) {
  var params = (e && e.parameter) ? e.parameter : {};

  if (params.action === 'image' && params.id) {
    try {
      return serveDriveImage_(params.id);
    } catch (err) {
      return ContentService.createTextOutput('Image not found').setMimeType(ContentService.MimeType.TEXT);
    }
  }

  var template = HtmlService.createTemplateFromFile('Index');
  template.loadError = 'null';
  template.scriptUrl = JSON.stringify(getScriptUrl_());
  template.pageMode = '"editor"';
  template.catalogData = '[]';

  if (params.page === 'catalog') {
    template.pageMode = '"catalog"';
    template.catalogData = JSON.stringify(listAllProjects_());
    template.initialProject = 'null';
    template.isEditMode = 'false';
  } else if (params.template) {
    try {
      var copyProject = getProjectForCopy_(params.template);
      template.initialProject = JSON.stringify(copyProject);
      template.isEditMode = 'true';
    } catch (err) {
      template.initialProject = 'null';
      template.isEditMode = 'true';
      template.loadError = JSON.stringify(err.message || String(err));
    }
  } else if (params.id) {
    try {
      var project = getProjectData_(params.id, params.edit);
      template.initialProject = JSON.stringify(project);
      template.isEditMode = project.canEdit ? 'true' : 'false';
    } catch (err) {
      template.initialProject = 'null';
      template.isEditMode = 'true';
      template.loadError = JSON.stringify(err.message || String(err));
    }
  } else {
    template.initialProject = 'null';
    template.isEditMode = 'true';
  }

  var pageTitle = 'מחולל מרחבי 360°';
  if (params.page === 'catalog') {
    pageTitle = 'קטלוג מרחבי 360°';
  } else if (params.id && !params.edit) {
    try {
      var p = getProjectData_(params.id, null);
      if (p.title) pageTitle = p.title;
    } catch (e) {}
  }

  return template.evaluate()
    .setTitle(pageTitle)
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1');
}

function include(filename) {
  return HtmlService.createHtmlOutputFromFile(filename).getContent();
}

// ——— API ללקוח ———

function saveProject(payload) {
  payload = payload || {};
  var sheet = getProjectsSheet_();
  var folder = getOrCreateDriveFolder_();
  var now = new Date().toISOString();
  var id = payload.id || '';
  var editToken = payload.editToken || '';
  var rowIndex = -1;
  var isNew = false;

  if (id) {
    rowIndex = findRowById_(sheet, id);
    if (rowIndex > 0) {
      verifyEditToken_(sheet, rowIndex, editToken);
    } else {
      id = '';
    }
  }

  if (!id) {
    id = generateShortId_();
    editToken = Utilities.getUuid();
    rowIndex = -1;
    isNew = true;
  }

  var image1Url = payload.image1 || '';
  var image2Url = payload.image2 || '';
  var thumbUrl = payload.image1Thumb || '';

  if (image1Url && image1Url.indexOf('data:') === 0) {
    image1Url = uploadBase64ToDrive_(image1Url, id + '_bg1.jpg', folder);
  }
  if (thumbUrl && thumbUrl.indexOf('data:') === 0) {
    thumbUrl = uploadBase64ToDrive_(thumbUrl, id + '_thumb.jpg', folder);
  } else if (!thumbUrl && rowIndex > 0) {
    thumbUrl = String(sheet.getRange(rowIndex, 13).getValue() || '');
  }

  var hotspots = uploadHotspotImages_(payload.hotspots || [], id, folder);

  var openingMessage = payload.openingMessage || {};
  if (openingMessage.image && openingMessage.image.indexOf('data:') === 0) {
    openingMessage.image = uploadBase64ToDrive_(openingMessage.image, id + '_opening.jpg', folder);
  }

  var rowData = buildRowData_({
    id: id,
    title: payload.title || 'מרחב 360',
    createdAt: rowIndex > 0 ? sheet.getRange(rowIndex, 3).getValue() : now,
    updatedAt: now,
    image1: image1Url,
    image2: image2Url,
    activeImage: payload.activeImage || 1,
    hotspots: hotspots,
    editToken: editToken,
    image1Thumb: thumbUrl,
    creatorName: payload.creatorName || '',
    creatorInfo: payload.creatorInfo || '',
    hideFromCatalog: !!payload.hideFromCatalog,
    openingMessage: openingMessage,
    enforceOrder: !!payload.enforceOrder
  });

  if (rowIndex > 0) {
    sheet.getRange(rowIndex, 1, 1, rowData.length).setValues([rowData]);
  } else {
    sheet.appendRow(rowData);
  }

  var baseUrl = getScriptUrl_();
  return {
    success: true,
    id: id,
    editToken: editToken,
    image1: image1Url,
    image1Thumb: thumbUrl,
    image2: image2Url,
    viewUrl: baseUrl + '?id=' + id,
    editUrl: baseUrl + '?id=' + id + '&edit=' + editToken,
    copyUrl: baseUrl + '?template=' + id,
    isNew: isNew
  };
}

function uploadBackgroundImages(payload) {
  payload = payload || {};
  var sheet = getProjectsSheet_();
  var folder = getOrCreateDriveFolder_();
  var now = new Date().toISOString();
  var id = payload.id || '';
  var editToken = payload.editToken || '';
  var rowIndex = -1;

  if (id) {
    rowIndex = findRowById_(sheet, id);
    if (rowIndex > 0) {
      verifyEditToken_(sheet, rowIndex, editToken);
    } else {
      id = '';
    }
  }

  if (!id) {
    id = generateShortId_();
    editToken = Utilities.getUuid();
    sheet.appendRow(buildRowData_({
      id: id,
      title: payload.title || 'מרחב חדש',
      createdAt: now,
      updatedAt: now,
      editToken: editToken,
      hotspots: []
    }));
    rowIndex = sheet.getLastRow();
  }

  var image1Url = '';
  var thumbUrl = '';
  if (payload.image1 && payload.image1.indexOf('data:') === 0) {
    image1Url = uploadBase64ToDrive_(payload.image1, id + '_bg1.jpg', folder);
  } else if (payload.image1) {
    image1Url = payload.image1;
  }
  if (payload.thumb && payload.thumb.indexOf('data:') === 0) {
    thumbUrl = uploadBase64ToDrive_(payload.thumb, id + '_thumb.jpg', folder);
  }

  sheet.getRange(rowIndex, 4).setValue(now);
  if (image1Url) sheet.getRange(rowIndex, 5).setValue(image1Url);
  if (thumbUrl) sheet.getRange(rowIndex, 13).setValue(thumbUrl);
  if (payload.title) sheet.getRange(rowIndex, 2).setValue(payload.title);

  return {
    id: id,
    editToken: editToken,
    image1: image1Url,
    image1Thumb: thumbUrl
  };
}

function getProject(id) {
  return getProjectData_(id, null);
}

function listProjects() {
  return listAllProjects_();
}

function getImageAsDataUrl(fileId) {
  var id = extractDriveId_(fileId);
  var file = DriveApp.getFileById(id);
  var maxBytes = 12 * 1024 * 1024;
  if (file.getSize() > maxBytes) {
    throw new Error('התמונה גדולה מדי (מעל 12MB). דחוס אותה לפני העלאה.');
  }
  var blob = file.getBlob();
  var mime = blob.getContentType() || 'image/jpeg';
  return 'data:' + mime + ';base64,' + Utilities.base64Encode(blob.getBytes());
}

function uploadImage(base64Data, fileName, projectId) {
  var folder = getOrCreateDriveFolder_();
  if (projectId) {
    var subFolders = folder.getFoldersByName(projectId);
    if (subFolders.hasNext()) {
      folder = subFolders.next();
    } else {
      folder = folder.createFolder(projectId);
    }
  }
  return uploadBase64ToDrive_(base64Data, fileName || ('img_' + Date.now() + '.jpg'), folder);
}

// ——— פנימי ———

function getProjectData_(id, editToken) {
  var sheet = getProjectsSheet_();
  var rowIndex = findRowById_(sheet, id);
  if (rowIndex < 0) {
    throw new Error('הפרויקט לא נמצא');
  }

  var row = sheet.getRange(rowIndex, 1, 1, CONFIG.HEADERS.length).getValues()[0];
  var storedToken = String(row[8]);
  var canEdit = !!(editToken && editToken === storedToken);
  var baseUrl = getScriptUrl_();

  var hotspots = [];
  try {
    hotspots = JSON.parse(row[7] || '[]');
  } catch (e) {
    hotspots = [];
  }

  hotspots = inlineDriveImagesInHotspots_(hotspots);

  var openingMessage = parseJsonSafe_(row[16], {});
  if (openingMessage.image) {
    openingMessage.image = inlineImageIfDrive_(openingMessage.image);
  }

  return {
    id: String(row[0]),
    title: String(row[1]),
    createdAt: row[2],
    updatedAt: row[3],
    image1: inlineImageIfDrive_(String(row[4] || '')),
    image2: inlineImageIfDrive_(String(row[5] || '')),
    image1Thumb: String(row[12] || ''),
    activeImage: parseInt(row[6], 10) || 1,
    hotspots: hotspots,
    canEdit: canEdit,
    editToken: canEdit ? storedToken : '',
    viewUrl: String(row[9] || baseUrl + '?id=' + row[0]),
    editUrl: canEdit ? String(row[10] || baseUrl + '?id=' + row[0] + '&edit=' + storedToken) : '',
    copyUrl: String(row[11] || baseUrl + '?template=' + row[0]),
    creatorName: String(row[13] || ''),
    creatorInfo: String(row[14] || ''),
    hideFromCatalog: String(row[15]).toLowerCase() === 'true',
    openingMessage: openingMessage,
    enforceOrder: String(row[17]).toLowerCase() === 'true'
  };
}

function getProjectForCopy_(id) {
  var data = getProjectData_(id, null);
  data.id = '';
  data.editToken = '';
  data.canEdit = true;
  data.isTemplate = true;
  data.viewUrl = '';
  data.editUrl = '';
  data.copyUrl = '';
  if (data.title && data.title.indexOf('(עותק)') === -1) {
    data.title = data.title + ' (עותק)';
  }
  return data;
}

function listAllProjects_() {
  var sheet = getProjectsSheet_();
  var data = sheet.getDataRange().getValues();
  var baseUrl = getScriptUrl_();
  var list = [];

  for (var i = 1; i < data.length; i++) {
    if (!data[i][0]) continue;
    if (String(data[i][15]).toLowerCase() === 'true') continue;

    var hs = [];
    try { hs = JSON.parse(data[i][7] || '[]'); } catch (e) { hs = []; }

    list.push({
      id: String(data[i][0]),
      title: String(data[i][1] || 'ללא שם'),
      updatedAt: data[i][3] ? String(data[i][3]) : '',
      image1: String(data[i][4] || ''),
      image1Thumb: String(data[i][12] || ''),
      hotspotCount: hs.length,
      creatorName: String(data[i][13] || ''),
      viewUrl: String(data[i][9] || baseUrl + '?id=' + data[i][0]),
      copyUrl: String(data[i][11] || baseUrl + '?template=' + data[i][0])
    });
  }

  list.sort(function(a, b) {
    return String(b.updatedAt).localeCompare(String(a.updatedAt));
  });

  return list;
}

function getProjectsSheet_() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) {
    sheet = ss.insertSheet(CONFIG.SHEET_NAME);
    sheet.appendRow(CONFIG.HEADERS);
    sheet.setFrozenRows(1);
    sheet.getRange(1, 1, 1, CONFIG.HEADERS.length).setFontWeight('bold');
  } else {
    ensureHeaders_(sheet);
  }
  return sheet;
}

function ensureHeaders_(sheet) {
  var lastCol = sheet.getLastColumn();
  var existing = sheet.getRange(1, 1, 1, Math.max(lastCol, CONFIG.HEADERS.length)).getValues()[0];
  for (var i = 0; i < CONFIG.HEADERS.length; i++) {
    if (!existing[i] || existing[i] !== CONFIG.HEADERS[i]) {
      sheet.getRange(1, 1, 1, CONFIG.HEADERS.length).setValues([CONFIG.HEADERS]);
      sheet.getRange(1, 1, 1, CONFIG.HEADERS.length).setFontWeight('bold');
      break;
    }
  }
}

function findRowById_(sheet, id) {
  var data = sheet.getDataRange().getValues();
  for (var i = 1; i < data.length; i++) {
    if (String(data[i][0]) === String(id)) {
      return i + 1;
    }
  }
  return -1;
}

function getOrCreateDriveFolder_() {
  if (CONFIG.DRIVE_FOLDER_ID) {
    return DriveApp.getFolderById(CONFIG.DRIVE_FOLDER_ID);
  }
  var folders = DriveApp.getFoldersByName(CONFIG.DRIVE_FOLDER_NAME);
  if (folders.hasNext()) {
    return folders.next();
  }
  return DriveApp.createFolder(CONFIG.DRIVE_FOLDER_NAME);
}

function inlineImageIfDrive_(imageUrl) {
  if (!imageUrl) return '';
  var str = String(imageUrl);
  if (str.indexOf('data:') === 0) return str;
  if (str.indexOf('drive.google') === -1 && str.indexOf('googleusercontent') === -1) {
    return str;
  }
  try {
    return getImageAsDataUrl(str);
  } catch (e) {
    Logger.log('inlineImageIfDrive failed: ' + e.message);
    return str;
  }
}

function verifyEditToken_(sheet, rowIndex, editToken) {
  var storedToken = String(sheet.getRange(rowIndex, 9).getValue());
  if (!editToken || editToken !== storedToken) {
    throw new Error('אין הרשאת עריכה לפרויקט זה');
  }
}

function parseJsonSafe_(val, fallback) {
  if (!val) return fallback;
  try { return JSON.parse(val); } catch (e) { return fallback; }
}

function buildRowData_(data) {
  var baseUrl = getScriptUrl_();
  var id = data.id;
  var token = data.editToken;
  return [
    id,
    data.title || 'מרחב 360',
    data.createdAt || new Date().toISOString(),
    data.updatedAt || new Date().toISOString(),
    data.image1 || '',
    data.image2 || '',
    data.activeImage || 1,
    JSON.stringify(data.hotspots || []),
    token,
    baseUrl + '?id=' + id,
    baseUrl + '?id=' + id + '&edit=' + token,
    baseUrl + '?template=' + id,
    data.image1Thumb || '',
    data.creatorName || '',
    data.creatorInfo || '',
    data.hideFromCatalog ? 'true' : 'false',
    JSON.stringify(data.openingMessage || {}),
    data.enforceOrder ? 'true' : 'false'
  ];
}

function uploadHotspotImages_(hotspots, id, folder) {
  for (var i = 0; i < hotspots.length; i++) {
    var hs = hotspots[i];
    if (hs.type === 'image' && hs.content && hs.content.indexOf('data:') === 0) {
      hs.content = uploadBase64ToDrive_(hs.content, id + '_hs_' + hs.id + '.jpg', folder);
    }
  }
  return hotspots;
}

function inlineDriveImagesInHotspots_(hotspots) {
  if (!hotspots || !hotspots.length) return hotspots || [];
  for (var i = 0; i < hotspots.length; i++) {
    if (hotspots[i].type === 'image' && hotspots[i].content) {
      hotspots[i].content = inlineImageIfDrive_(hotspots[i].content);
    }
  }
  return hotspots;
}

function serveDriveImage_(fileId) {
  var id = extractDriveId_(fileId);
  var file = DriveApp.getFileById(id);
  var blob = file.getBlob();
  return ContentService.createBlobOutput(blob).setMimeType(blob.getContentType() || 'image/jpeg');
}

function extractDriveId_(ref) {
  if (!ref) throw new Error('מזהה קובץ חסר');
  var str = String(ref);
  var patterns = [/[?&]id=([^&]+)/, /\/file\/d\/([^/]+)/, /\/d\/([^/]+)/];
  for (var i = 0; i < patterns.length; i++) {
    var m = str.match(patterns[i]);
    if (m) return m[1];
  }
  if (/^[a-zA-Z0-9_-]{20,}$/.test(str)) return str;
  throw new Error('מזהה Drive לא תקין');
}

function uploadBase64ToDrive_(base64Data, fileName, folder) {
  var parts = base64Data.split(',');
  var meta = parts[0];
  var data = parts[1];
  var mimeMatch = meta.match(/data:([^;]+)/);
  var mimeType = mimeMatch ? mimeMatch[1] : 'image/jpeg';
  var blob = Utilities.newBlob(Utilities.base64Decode(data), mimeType, fileName);
  var file = folder.createFile(blob);
  file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
  return 'https://drive.google.com/uc?export=view&id=' + file.getId();
}

function generateShortId_() {
  return Utilities.getUuid().replace(/-/g, '').substring(0, 10);
}

function getScriptUrl_() {
  if (CONFIG.WEB_APP_URL) {
    return CONFIG.WEB_APP_URL;
  }
  var url = ScriptApp.getService().getUrl();
  if (!url) {
    throw new Error('כתובת Web App לא מוגדרת — עדכן את CONFIG.WEB_APP_URL');
  }
  return url;
}

function setupSheet() {
  getProjectsSheet_();
  getOrCreateDriveFolder_();
  return 'ההגדרה הושלמה — גיליון ותיקיית Drive מוכנים';
}

function backfillUrls() {
  var sheet = getProjectsSheet_();
  var data = sheet.getDataRange().getValues();
  var baseUrl = getScriptUrl_();
  var count = 0;

  for (var i = 1; i < data.length; i++) {
    var id = String(data[i][0]);
    var token = String(data[i][8]);
    if (!id) continue;
    var rowIndex = i + 1;
    if (!data[i][9]) {
      sheet.getRange(rowIndex, 10).setValue(baseUrl + '?id=' + id);
      count++;
    }
    if (!data[i][10] && token) {
      sheet.getRange(rowIndex, 11).setValue(baseUrl + '?id=' + id + '&edit=' + token);
      count++;
    }
    if (!data[i][11]) {
      sheet.getRange(rowIndex, 12).setValue(baseUrl + '?template=' + id);
      count++;
    }
  }
  return 'עודכנו ' + count + ' קישורים';
}
