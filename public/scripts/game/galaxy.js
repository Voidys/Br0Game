import { getJSON, pretty_number } from '../app/core.js';

function doit(missionID, planetID) {
  getJSON("game?page=fleetAjax&ajax=1&mission=" + missionID + "&planetID=" + planetID).then(function (data) {
    if (data.code === 602) {
      //602 = needs confirmation
      if (!confirm(data.mess)) {
        return;
      }
      getJSON("game?page=fleetAjax&ajax=1&mission=" + missionID + "&planetID=" + planetID + "&confirm=1").then(function (data) {
        handleDoIt(data)
      });
      return;
    }

    handleDoIt(data)
  });
}

function handleDoIt(data) {
  document.getElementById('slots').textContent = data.slots;
  if (typeof data.ships !== "undefined") {
    Object.entries(data.ships).forEach(function ([elementID, value]) {
      const cell = document.getElementById('elementID' + elementID);
      if (cell) cell.textContent = pretty_number(value);
    });
  }

  let statustable = document.getElementById('fleetstatusrow');
  let messages = statustable.querySelectorAll(':scope > div');
  if (messages.length == MaxFleetSetting) {
    messages[0].remove();
  }
  let element = document.createElement('div');
  element.className = "galaxy-grid-item centered span6 " + (data.code === 600 ? "success" : "error");
  element.textContent = data.mess;
  statustable.hidden = false;
  statustable.append(element);

}

function showMissileForm(galaxy, system, planetPos, duration, type) {
  const form = document.querySelector('form[action="?page=fleetMissile"]');
  if (!form) return false;
  form.querySelector('input[name="galaxy"]').value = galaxy;
  form.querySelector('input[name="system"]').value = system;
  form.querySelector('input[name="planet"]').value = planetPos;
  form.querySelector('input[name="type"]').value = type;
  form.querySelector('input[name="duration"]').value = duration;
  const flyTime = document.getElementById('irakFlyTime');
  if (flyTime) flyTime.textContent = duration;
  const table = document.getElementById('missileForm');
  if (table) table.hidden = false;
  const sendInput = form.querySelector('input[name="SendMI"]');
  if (sendInput) sendInput.focus();
  return false;
}

function handleMissileAjax(data, galaxy, system, planetPos, duration, type) {
  if (data.code !== 600) {
    alert(data.mess);
    return;
  }
  showMissileForm(galaxy, system, planetPos, duration, type);
}

function openMissileForm(planetID, galaxy, system, planetPos, duration, type) {
  getJSON("game?page=fleetAjax&ajax=1&mission=10&planetID=" + planetID).then(function (data) {
    if (data.code === 602) {
      //602 = needs confirmation (become/remain outlaw vs strong player)
      if (!confirm(data.mess)) {
        return;
      }
      getJSON("game?page=fleetAjax&ajax=1&mission=10&planetID=" + planetID + "&confirm=1").then(function (data) {
        handleMissileAjax(data, galaxy, system, planetPos, duration, type);
      });
      return;
    }
    handleMissileAjax(data, galaxy, system, planetPos, duration, type);
  });
  return false;
}

function galaxy_submit(value) {
  document.getElementById('auto').setAttribute('name', value);
  document.getElementById('galaxy_form').submit();
}

const universe = GM_getValue("universe", {})
const playerids = GM_getValue("playerids", {})
const allyids = GM_getValue("allyids", {})

function parseUni() {
  return window.location.pathname.match('uni?.')?.[0] || 'uni1'
}

function GM_getValue(key, defaultv) {
  let uni = parseUni()
  let x = localStorage.getItem(`${uni}_${key}`);
  if (x === null) {
    return defaultv;
  }
  return JSON.parse(x)
}

function GM_setValue(key, value) {
  let uni = parseUni()
  localStorage.setItem(`${uni}_${key}`, JSON.stringify(value))
}


function addlisteners() {
  document.getElementById("gala_sync").onclick = () => {
    syncsys();
    document.getElementById("gala_sync").value = "Synced";
  }

  document.getElementById('gala_upload').addEventListener('change', onChange_import);

  document.getElementById("gala_exportjson").onclick = export_json
  document.getElementById("gala_exportcsv").onclick = export_csv
  document.getElementById("gala_reset").onclick = reset
  showplayerinfos();
}

onPageInit(function () {
  addlisteners();
});

function onChange_import(event) {
  var reader = new FileReader();
  reader.onload = onReaderLoad_import;
  reader.readAsText(event.target.files[0]);
}

function onReaderLoad_import(event) {
  var ttl = JSON.parse(event.target.result);
  let obj = ttl[0]
  let pid = ttl[1]
  let aid = ttl[2]
  for (let k in obj) {
    if (k in universe) {
      if (universe[k].timepoint < obj[k].timepoint) {
        universe[k] = obj[k]
      }
    } else {
      universe[k] = obj[k]
    }
  }
  for (let k in pid) {
    if (k in playerids) {
      if (playerids[k].timepoint < pid[k].timepoint) {
        playerids[k] = pid[k]
      }
    } else {
      playerids[k] = pid[k]
    }
  }
  for (let k in aid) {
    if (k in allyids) {
      if (allyids[k].timepoint < aid[k].timepoint) {
        allyids[k] = aid[k]
      }
    } else {
      allyids[k] = aid[k]
    }
  }
  GM_setValue("universe", universe)
  GM_setValue("playerids", playerids)
  GM_setValue("allyids", allyids)
}

function export_csv() {
  let keys = Object.keys(universe)
  keys.sort(compareFn)
  var outcsv = "coords,moon?,name,ally,status,\n"
  for (let k of keys) {
    for (let p = 1; p < 16; p++) {
      if (universe[k][p] !== null) {
        let hasmoon = universe[k][p].hasmoon ? 'X' : ''
        outcsv += k + ":" + p + "," + hasmoon + "," + playerids[universe[k][p].playerid].name + "(" + universe[k][p].playerid + ")" +
          "," + allyids[universe[k][p].allianceid].name + "(" + universe[k][p].allianceid + ")," + universe[k][p].special + ",\n"
      }
    }
  }
  download("universe.csv", outcsv)
}

function export_json() {
  download("universe.json", JSON.stringify([universe, playerids, allyids]))
}

function reset() {
  if (confirm(confirmlng) === false) {
    return;
  }
  Object.keys(universe).forEach(key => delete universe[key]);
  Object.keys(playerids).forEach(key => delete playerids[key]);
  Object.keys(allyids).forEach(key => delete allyids[key]);
  GM_setValue("universe", universe)
  GM_setValue("playerids", playerids)
  GM_setValue("allyids", allyids)
}

function syncsys() {
  universe[galakey] = systemdata;
  for (let pos in systemdata) {
    if (pos === "timepoint" || systemdata[pos] === null) {
      continue
    }
    playerids[systemdata[pos].playerid] = {name: systemdata[pos].name, timepoint: systemdata.timepoint}
    allyids[systemdata[pos].allianceid] = {name: systemdata[pos].alliancename, timepoint: systemdata.timepoint}
  }
  GM_setValue("universe", universe)
  GM_setValue("playerids", playerids)
  GM_setValue("allyids", allyids)

}

function showplayerinfos() {
  for (let username of document.getElementsByClassName(" galaxy-username")) {
    annotatehover(username.getAttribute("playerid"), username.parentElement)
  }
}

function annotatehover(id, htmlobject) {
  let anostr = htmlobject.getAttribute("data-tooltip-content").replace("</table>", "")
  anostr += "<tr><th colspan='2'>"+planetlng+"</th></tr>"
  let baseurl = location.href.split("?")[0] + "?page=galaxy&galaxy="
  let toshow = []
  for (let k in universe) {
    for (let p = 1; p < 16; p++) {
      if (universe[k][p] === null) {
        continue
      }
      if (universe[k][p].playerid == id) {
        let gala = parseInt(k.split(":")[0])
        let sys = parseInt(k.split(":")[1])
        toshow.push([gala, sys, p, universe[k][p].hasmoon ? moonshortlng : ''])
      }
    }
  }
  toshow.sort()
  let cgala = galakey.split(":")[0]
  let csys = galakey.split(":")[1]
  for (let coord of toshow) {
    if (coord[0] == cgala && coord[1] == csys) {
      anostr += "<tr><td colspan='2' style='text-align: center'><span>" + getcoordstring(coord) + "</span></td></tr>"
    } else {
      if (coord[0] == cgala) {
        anostr += "<tr><th colspan='2' style='text-align: center'><a href='" + baseurl + coord[0] + "&system=" + coord[1] + "'>" + getcoordstring(coord) + "</a></th></tr>"
      } else {
        anostr += "<tr><td colspan='2' style='text-align: center'><a href='" + baseurl + coord[0] + "&system=" + coord[1] + "'>" + getcoordstring(coord) + "</a></td></tr>"
      }
    }
  }

  htmlobject.setAttribute("data-tooltip-content", anostr + "</table>")
}

function getcoordstring(coord) {
  let x = "[" + coord[0] + ":" + coord[1] + ":" + coord[2] + "]" + " " + coord[3]
  return x.trim()
}


function compareFn(a, b) {
  let spa = a.split(":")
  let spb = b.split(":")
  if (parseInt(spa[0]) < parseInt(spb[0])) {
    return -1;
  } else {
    if (spa[0] !== spb[0]) {
      return 1
    }
  }
  if (parseInt(spa[1]) < parseInt(spb[1])) {
    return -1;
  } else {
    if (spa[1] !== spb[1]) {
      return 1
    }
  }
  return 0;
}

function download(filename, text) {
  var element = document.createElement('a');
  element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(text));
  element.setAttribute('download', filename);

  element.style.display = 'none';
  document.body.appendChild(element);

  element.click();

  document.body.removeChild(element);
}


var keypressRegistered = false;
document.addEventListener('keydown', logKey);

function logKey(e) {
  if (!keypressRegistered) {
    const callback = {
      "a": leftHandler,
      "ArrowLeft": leftHandler,
      "d": rightHandler,
      "ArrowRight": rightHandler,
      "w": upHandler,
      "ArrowUp": upHandler,
      "s": downHandler,
      "ArrowDown": downHandler,
    }[e.key]
    callback?.()
  }
}

function leftHandler() {
  keypressRegistered = true;
  galaxy_submit('systemLeft');
}

function rightHandler() {
  keypressRegistered = true;
  galaxy_submit('systemRight');
}

function upHandler() {
  keypressRegistered = true;
  galaxy_submit('galaxyLeft');
}

function downHandler() {
  keypressRegistered = true;
  galaxy_submit('galaxyRight');
}

// inline handlers and javascript: links of the galaxy templates and tooltips
Object.assign(window, { doit, openMissileForm, showMissileForm, galaxy_submit });
