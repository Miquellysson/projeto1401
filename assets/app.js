const LS_CART_KEY = 'ff_cart';
export const cart = {
  get(){ try { return JSON.parse(localStorage.getItem(LS_CART_KEY)||'[]'); } catch(e){ return []; } },
  set(items){ localStorage.setItem(LS_CART_KEY, JSON.stringify(items)); },
  add(p){ const items=this.get(); const i=items.findIndex(x=>x.id===p.id); if(i>=0){ items[i].qty+=1; } else { items.push({...p, qty:1}); } this.set(items); },
  rm(id){ const items=this.get().filter(x=>x.id!==id); this.set(items); },
  clear(){ localStorage.removeItem(LS_CART_KEY); },
  sum(){ return this.get().reduce((s,i)=>s+i.price*i.qty,0); }
};