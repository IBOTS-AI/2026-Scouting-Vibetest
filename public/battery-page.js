(() => {
  document.querySelectorAll('[data-battery-today]').forEach(input => {const now=new Date();input.value=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;});
  const table=document.getElementById('batteryTable');if(!table)return;
  const body=table.tBodies[0],rows=[...body.rows],search=document.getElementById('batterySearch'),status=document.getElementById('batterySearchStatus');
  search?.addEventListener('input',()=>{const text=search.value.trim().toLowerCase();rows.forEach(row=>row.hidden=!row.dataset.batterySearch.includes(text));if(status)status.textContent=`${rows.filter(row=>!row.hidden).length} of ${rows.length} batteries shown.`;});
  let column=0,direction=1;
  table.querySelectorAll('[data-battery-sort]').forEach(button=>button.addEventListener('click',()=>{
    const next=Number(button.dataset.batterySort);direction=next===column?-direction:1;column=next;
    const value=row=>row.cells[column].dataset.sort??row.cells[column].textContent.trim();
    const ordered=[...rows].sort((a,b)=>{const av=value(a),bv=value(b),empty=v=>v===''||v==='—';if(empty(av)!==empty(bv))return empty(av)?1:-1;const cmp=column>=4?Number(av)-Number(bv):av.localeCompare(bv,undefined,{numeric:true,sensitivity:'base'});return direction*cmp||a.cells[0].textContent.localeCompare(b.cells[0].textContent,undefined,{numeric:true});});
    ordered.forEach(row=>body.append(row));table.querySelectorAll('th').forEach(th=>th.removeAttribute('aria-sort'));button.closest('th').setAttribute('aria-sort',direction===1?'ascending':'descending');
  }));
})();
