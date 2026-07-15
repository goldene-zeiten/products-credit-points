CREATE TABLE tx_products_domain_model_creditpointsbalance (
    frontend_user int(11) DEFAULT '0' NOT NULL,
    balance int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (frontend_user)
);
