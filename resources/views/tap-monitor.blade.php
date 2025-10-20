{{-- resources/views/tap-monitor.blade.php --}}
<!doctype html><html><head><meta charset="utf-8"><title>Tap Monitor</title>
<style>body{font:14px system-ui;margin:20px} table{border-collapse:collapse} td,th{border:1px solid #ddd;padding:6px 10px}</style>
</head><body>
<h3>Tap Monitor (last 5)</h3>
<table id="t"><thead><tr><th>ID</th><th>UID</th><th>Reservation</th><th>Room</th><th>Time</th></tr></thead><tbody></tbody></table>
<script>
async function tick(){
  try{
    const r = await fetch('/api/card-scans/latest');
    const rows = await r.json();
    const tb = document.querySelector('#t tbody'); tb.innerHTML='';
    rows.forEach(x=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${x.id}</td><td>${x.uid_norm||x.uid_raw||'-'}</td><td>${x.reservation_id??'-'}</td><td>${x.room_id??'-'}</td><td>${x.created_at}</td>`;
      tb.appendChild(tr);
    });
  }catch(e){}
}
tick(); setInterval(tick, 1000);
</script>
</body></html>
