import { qsa, now, pad, pretty_number, setVisibleInterval, NotifyBox } from '../app/core.js';

onPageInit(function () {
  setVisibleInterval(function () {
    qsa('.fleets').forEach(function (el) {
      var s = Number(el.dataset.fleetTime) - (now().getTime() - startTime) / 1000;
      if (s <= 0) {
        el.textContent = '-';
      } else {
        el.textContent = getRestTimeFormat(s);
      }
    })
    const abortfleet = document.getElementsByClassName("aborttime")


    for (let fleet of abortfleet) {
      const current = new Date(Date.now())
      const deT = new Date(current.toLocaleString('en-us', { timeZone: 'Europe/Berlin'})).getTime() 
      const localT = new Date(current.toLocaleString('en-us', { timeZone: localtimezonestring})).getTime()
      const timeOffset = localT - deT
      let cdate = new Date(serverTime.getTime() + timeOffset) 
      let startdate = new Date(new Date(1000 * parseInt(fleet.getAttribute("starttime"))).toLocaleString('en-US', {timeZone: localtimezonestring}))
      let difftonow = cdate - startdate
      cdate.setMilliseconds(cdate.getMilliseconds() + difftonow)
      let daydiff = Math.trunc((cdate - new Date(serverTime)) / (1000 * 60 * 60 * 24));

      if (daydiff > 0) {
        fleet.innerText = daydiff + "T " + pad(cdate.getHours(), 2) + ":" + pad(cdate.getMinutes(), 2) + ":" + pad(cdate.getSeconds(), 2)
      } else {
        fleet.innerText = pad(cdate.getHours(), 2) + ":" + pad(cdate.getMinutes(), 2) + ":" + pad(cdate.getSeconds(), 2)
      }
    }
  }, 500);
});


function add_exp_eventlisteners() {
  for (let sid in exp_values) {
    let shipinpt = document.getElementById("ship" + sid + "_input")
    if (shipinpt) {
      shipinpt.addEventListener("input", () => setTimeout(show_values, 100));
    }
  }
}

function show_values() {
  let fpoints = 0
  let tpoints = 0
  for (let s in exp_values) {
    let shipinpt = document.getElementById("ship" + s + "_input")
    if (shipinpt && shipinpt.value !== "") {
      fpoints += exp_values[s] * parseInt(shipinpt.value)
      tpoints += ship_storage[s] * parseInt(shipinpt.value)
    }
  }
  const expoCount = document.getElementById("expocount");
  expoCount.innerHTML = "Exp: " + fpoints + " / " + expoCount.getAttribute("expocap")
    + ' <svg class="icon" aria-hidden="true"><use href="./styles/resource/icons/lucide.svg#i-info"></use></svg>';
  document.getElementById("cargospace").innerText = document.getElementById("cargospace").getAttribute("data") + ":" + pretty_number(tpoints)
}


function addstoragelisteners() {
  document.getElementsByName("met_storage")[0].addEventListener("input", updatebuttons);
  document.getElementsByName("krist_storage")[0].addEventListener("input", updatebuttons);
  document.getElementsByName("deut_storage")[0].addEventListener("input", updatebuttons);
  document.getElementsByName("met_storage")[0].value = parseInt(document.getElementById("current_metal").getAttribute("data-real"))
  document.getElementsByName("krist_storage")[0].value = parseInt(document.getElementById("current_crystal").getAttribute("data-real"))
  document.getElementsByName("deut_storage")[0].value = parseInt(document.getElementById("current_deuterium").getAttribute("data-real"))
  document.getElementById("gt_select").addEventListener("click", setamt);
  document.getElementById("kt_select").addEventListener("click", setamt);
}

function updatebuttons() {
  let ttl = 0
  let met = parseInt(document.getElementsByName("met_storage")[0].value.replace(/\D/g, ""), 10)
  let krist = parseInt(document.getElementsByName("krist_storage")[0].value.replace(/\D/g, ""), 10)
  let deut = parseInt(document.getElementsByName("deut_storage")[0].value.replace(/\D/g, ""), 10)
  ttl += isNaN(met) ? 0 : met
  ttl += isNaN(krist) ? 0 : krist
  ttl += isNaN(deut) ? 0 : deut
  let gts = Math.ceil(ttl / 25000)
  let kts = Math.ceil(ttl / 5000)
  if (ttl / 25000 - gts > 0.8) {
    gts += 1
  }
  if (ttl / 5000 - kts > 0.5) {
    kts += 1
  }
  document.getElementById("kt_amt").innerText = " ( " + pretty_number(kts) + " ) "
  document.getElementById("gt_amt").innerText = " ( " + pretty_number(gts) + " ) "
}

function setamt() {
  noShips()
  if (this.id === "gt_select") {
    let gt = document.getElementById("ship203_input")
    if (gt !== null) {
      gt.value = document.getElementById("gt_amt").innerText.replace(/\D/g, "")
    }
  } else {
    let gt = document.getElementById("ship202_input")
    if (gt) {
      gt.value = document.getElementById("kt_amt").innerText.replace(/\D/g, "")
    }
  }
  show_values();
}

function toogle_custom_fleet() {
  const table = document.getElementById("customfleet")
  if (table.style.display === "none") {
    table.style.display = "table";
    document.getElementById("c_fleet_span").innerText = "▲"
  } else {
    table.style.display = "none";
    document.getElementById("c_fleet_span").innerText = "▼"
  }

}


function save_fleet_Select() {
  let obj = {}
  for (let shipid in exp_values) {
    if (document.getElementById("ship_" + shipid) === null) {
      continue
    }

    obj[shipid] = document.getElementById("ship_" + shipid)?.value
  }
  let fname = document.getElementById("cfleet_name").value
  if (fname.trim() === "") {
    alert(document.getElementById("customfleet").getAttribute("data-noname"))
    return;
  }
  if (!(fname in cfleet)) {
    let obj = document.createElement("option");
    obj.value = fname;
    obj.innerText = fname;
    document.getElementById("cfleet_select").appendChild(obj);
  }
  document.getElementById("cfleet_select").value = fname
  cfleet[fname] = obj
  local_setValue("custom_fleets", cfleet)
  customfleet_show()
  NotifyBox(document.getElementById("customfleet").getAttribute("data-saved") || "OK")
}

function change_fleet_select() {

  showfleet(document.getElementById("cfleet_select").value);
}

function cf_remove() {
  let oname = document.getElementById("cfleet_select").value
  let rv = confirm(document.getElementById("customfleet").getAttribute("data-delconf").replace("%s",oname))

  if (rv === false) {
    return;
  }

  document.getElementById("cfleet_name").value = "";
  let rmv = document.querySelector("#cfleet_select option[value='" + oname + "']")
  if (rmv) {
    rmv.remove()
    delete cfleet[oname];
    showfleet(document.getElementById("cfleet_select").value)
  }

  local_setValue("custom_fleets", cfleet)
  customfleet_show()
}

function showfleet(name) {
  document.getElementById("cfleet_name").value = name;
  const fleet = cfleet[name] || {}
  for (let sid in fleet) {
    const ship = document.getElementById("ship_" + sid)
    if (ship === null) {
      continue
    }
    ship.value = fleet[sid]

  }
  if (name === "") {
    for (let f in exp_values) {
      if (document.getElementById("ship_" + f) === null) {
        continue;
      }
      document.getElementById("ship_" + f).value = 0

    }
  }
  showexpopoints();
}

function showexpopoints() {
  let fleet = {}
  for (let f in exp_values) {
    if (document.getElementById("ship_" + f) === null) {
      continue
    }

    fleet[f] = document.getElementById("ship_" + f).value

  }

  document.getElementById("ship_expo_points").innerText = pretty_number(calcexpopoints(fleet))
  document.getElementById("ship_cargo_points").innerText = pretty_number(calcstoragepoints(fleet))


}

function calcstoragepoints(fleet) {
  let points = 0;
  for (let f in fleet) {
    if (ship_storage[f] === null) {
      continue;
    }
    points += ship_storage[f] * parseInt(fleet[f])

  }
  return points;


}

function calcexpopoints(fleet) {
  let points = 0;
  for (let f in fleet) {
    if (exp_values[f] === null) {
      continue;
    }
    points += exp_values[f] * parseInt(fleet[f])

  }
  return points;


}

let cfleet = {}

function local_getValue(key, defaultv) {
  let x = localStorage.getItem(key);
  if (x === null) {
    return defaultv;
  }
  return JSON.parse(x)
}

function local_setValue(key, value) {
  localStorage.setItem(key, JSON.stringify(value))
}


function loadcustomfleets() {
  cfleet = local_getValue("custom_fleets", {})
  for (let cf in cfleet) {
    showfleet(cf)
    break
  }
  for (let cf in cfleet) {
    let obj = document.createElement("option");
    obj.value = cf;
    obj.innerText = cf;
    document.getElementById("cfleet_select").appendChild(obj);
  }

  document.getElementById("cfleet_select").addEventListener("change", change_fleet_select);
  document.getElementById("cf_save").addEventListener("click", save_fleet_Select);
  document.getElementById("cf_del").addEventListener("click", cf_remove);
  for (let f in exp_values) {
    if (document.getElementById("ship_" + f) === null) {
      continue
    }

    document.getElementById("ship_" + f)?.addEventListener("input", showexpopoints);

  }
  showexpopoints();

}
function custom_fleet(k) {
  noShips();
  const custom_fleet = local_getValue("custom_fleets", {})[k]
  for (let stype in custom_fleet) {
    let obj = document.getElementById(`ship${stype}_input`)
    if (obj) {
      obj.value = custom_fleet[stype]

    }

  }
  show_values();

}
function customfleet_show() {
  let cfleet = local_getValue("custom_fleets", {})
  let x = ""
  for (let k in cfleet) {
    x += '   <a  href="javascript:;" onclick="custom_fleet(\''+k+'\')">' + k + '</a>'

  }
  document.getElementById("customfleets").innerHTML=x

}

function fillShipps(){
  let ships = [...document.location.hash.matchAll(/ship_input\[(?<shiptype>[0-9]+)\]=(?<shipamount>[0-9]+)/g)];
  ships.forEach(ship => {
    let input = document.querySelector(`input#ship${ship.groups.shiptype}_input`);
    if (input !== null) {
      input.value = ship.groups.shipamount;
    }
  });  
  show_values();
};


onPageInit(function () {
  show_values()
  add_exp_eventlisteners()
  addstoragelisteners()
  updatebuttons();
  loadcustomfleets();
  customfleet_show();
  fillShipps()
});

// inline handlers (fleet table template), customfleet links and flotten.js
Object.assign(window, {
  show_values, toogle_custom_fleet, custom_fleet,
});
