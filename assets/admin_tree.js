(function () {
  var config = document.getElementById('tree-config');
  var body = document.getElementById('tree-body');
  var breadcrumb = document.getElementById('tree-breadcrumb');
  var truncatedNote = document.getElementById('tree-truncated-note');
  if (!config || !body) {
    return;
  }

  var treeUrl = config.getAttribute('data-tree-url');
  var previewUrl = config.getAttribute('data-preview-url');
  var downloadUrl = config.getAttribute('data-download-url');

  function formatBytes(bytes) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    bytes = Number(bytes);
    while (bytes >= 1024 && i < units.length - 1) {
      bytes /= 1024;
      i++;
    }
    return (i === 0 ? Math.round(bytes) : bytes.toFixed(1)) + ' ' + units[i];
  }

  function el(tag, className, text) {
    var e = document.createElement(tag);
    if (className) {
      e.className = className;
    }
    if (text !== undefined) {
      e.textContent = text;
    }
    return e;
  }

  function renderBreadcrumb(prefix) {
    breadcrumb.innerHTML = '';
    var root = el('a', 'hover:underline cursor-pointer', 'root');
    root.addEventListener('click', function () { load(''); });
    breadcrumb.appendChild(root);

    var parts = prefix.split('/').filter(Boolean);
    var acc = '';
    parts.forEach(function (part) {
      acc += part + '/';
      breadcrumb.appendChild(el('span', null, ' / '));
      (function (target) {
        var a = el('a', 'hover:underline cursor-pointer', part);
        a.addEventListener('click', function () { load(target); });
        breadcrumb.appendChild(a);
      })(acc);
    });
  }

  function folderRow(prefix, folder) {
    var tr = document.createElement('tr');
    tr.className = 'hover:bg-gray-50 cursor-pointer';

    var nameTd = el('td', 'px-4 py-3 font-medium text-gray-800 flex items-center gap-2');
    var icon = el('i', 'material-icons text-lg text-gray-400', 'folder');
    nameTd.appendChild(icon);
    nameTd.appendChild(document.createTextNode(folder.name + '/'));
    tr.appendChild(nameTd);

    tr.appendChild(el('td', 'px-4 py-3 text-gray-400', folder.count + ' item' + (folder.count === 1 ? '' : 's')));
    tr.appendChild(el('td', 'px-4 py-3 text-gray-400', '—'));
    tr.appendChild(el('td', 'px-4 py-3 text-gray-400', '—'));
    tr.appendChild(el('td', 'px-4 py-3'));

    tr.addEventListener('click', function () { load(prefix + folder.name + '/'); });
    return tr;
  }

  function fileRow(file) {
    var tr = document.createElement('tr');
    tr.className = 'hover:bg-gray-50';

    var nameTd = el('td', 'px-4 py-3 text-gray-800 flex items-center gap-2');
    var icon = el('i', 'material-icons text-lg text-gray-300', 'description');
    nameTd.appendChild(icon);
    nameTd.appendChild(document.createTextNode(file.name));
    tr.appendChild(nameTd);

    tr.appendChild(el('td', 'px-4 py-3 text-gray-600', formatBytes(file.size)));
    tr.appendChild(el('td', 'px-4 py-3 text-gray-500', file.content_type || '—'));
    tr.appendChild(el('td', 'px-4 py-3 text-gray-500', file.created_at || '—'));

    var actionsTd = el('td', 'px-4 py-3 text-right space-x-3 whitespace-nowrap');
    var previewLink = document.createElement('a');
    previewLink.href = previewUrl + '?key=' + encodeURIComponent(file.key);
    previewLink.className = 'text-gray-500 hover:text-gray-900';
    previewLink.title = 'Preview';
    var previewIcon = el('i', 'material-icons text-lg align-middle', 'visibility');
    previewLink.appendChild(previewIcon);
    actionsTd.appendChild(previewLink);

    var downloadLink = document.createElement('a');
    downloadLink.href = downloadUrl + '?key=' + encodeURIComponent(file.key);
    downloadLink.className = 'text-gray-500 hover:text-gray-900';
    downloadLink.title = 'Download';
    var downloadIcon = el('i', 'material-icons text-lg align-middle', 'download');
    downloadLink.appendChild(downloadIcon);
    actionsTd.appendChild(downloadLink);

    tr.appendChild(actionsTd);
    return tr;
  }

  function messageRow(className, text) {
    var tr = document.createElement('tr');
    var td = el('td', className, text);
    td.colSpan = 5;
    tr.appendChild(td);
    return tr;
  }

  function load(prefix) {
    renderBreadcrumb(prefix);
    body.innerHTML = '';
    body.appendChild(messageRow('px-4 py-8 text-center text-gray-400', 'Loading…'));

    fetch(treeUrl + '?prefix=' + encodeURIComponent(prefix))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        body.innerHTML = '';
        if (!data.folders.length && !data.files.length) {
          body.appendChild(messageRow('px-4 py-8 text-center text-gray-400', 'Empty.'));
        }
        data.folders.forEach(function (f) { body.appendChild(folderRow(prefix, f)); });
        data.files.forEach(function (f) { body.appendChild(fileRow(f)); });
        truncatedNote.classList.toggle('hidden', !data.truncated);
      })
      .catch(function () {
        body.innerHTML = '';
        body.appendChild(messageRow('px-4 py-8 text-center text-red-500', 'Failed to load.'));
      });
  }

  load('');
})();
