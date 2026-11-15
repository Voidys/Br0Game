alter table `%PREFIX%vars`
    add bigshield boolean default 0 not null after defend,
    add stun float default 0 not null after bigshield;

